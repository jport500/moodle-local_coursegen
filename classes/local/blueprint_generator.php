<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Generates the course blueprint from a job's source corpus.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

use local_coursegen\local\ai\text_client;
use local_coursegen\local\ai\text_result;

/**
 * Stage 3 of the pipeline (SPEC §2): turns the normalized corpus into the
 * editable blueprint IR via the reasoning tier, map-reducing when the corpus
 * exceeds the configured working budget. Persists the blueprint (D8), records
 * the cost estimate (SPEC §7), logs the resolved provider/model (SPEC §10.2),
 * and advances the job extracted → blueprinted. No course content is created.
 */
class blueprint_generator {
    /** @var int Fallback reasoning working budget if unconfigured (tokens). */
    private const DEFAULT_BUDGET_TOKENS = 12000;

    /** @var string Pipeline stage name for the audit log. */
    private const STAGE = 'blueprint';

    /** @var string Capability tier used. */
    private const TIER = 'reasoning';

    /**
     * Construct the generator.
     *
     * @param text_client $client The reasoning-tier text client (injectable for tests).
     */
    public function __construct(
        /** @var text_client The reasoning-tier text client. */
        private text_client $client,
    ) {
    }

    /**
     * Generate, persist and finalize the blueprint for a job.
     *
     * @param \stdClass $job The coursegen_job row (expected status: extracted).
     * @return bool True on success; false if the job was failed.
     */
    public function generate_for_job(\stdClass $job): bool {
        global $DB;
        $context = \context::instance_by_id($job->contextid, IGNORE_MISSING);
        if (!$context) {
            $this->fail_job($job, null, 'context missing');
            return false;
        }

        [$blocks, $tokens] = $this->gather_corpus($job);
        if ($blocks === []) {
            $this->fail_job($job, $context, 'empty corpus');
            return false;
        }

        $budget = $this->budget_tokens();
        if ($tokens <= $budget) {
            $sourcetext = $this->render_blocks($blocks);
        } else {
            $sourcetext = $this->map_reduce($blocks, $budget, $job, $context);
            if ($sourcetext === null) {
                // The map-reduce step already failed the job.
                return false;
            }
        }

        $result = $this->call_and_log($this->blueprint_prompt($sourcetext), $job, $context, 'synthesis');
        if (!$result->success) {
            $this->set_status($job, job_manager::STATUS_FAILED);
            return false;
        }

        $decoded = blueprint::decode_object($result->content);
        if ($decoded === null) {
            $this->fail_job($job, $context, 'blueprint response was not valid JSON');
            return false;
        }
        $blueprint = blueprint::from_array($decoded);
        if (!$blueprint->is_valid()) {
            $this->fail_job($job, $context, 'blueprint missing title or sections');
            return false;
        }

        blueprint_store::save_new_version($job, $blueprint, (int) $job->userid);
        $DB->set_field('coursegen_job', 'estimatedspend', $blueprint->estimate_units(), ['id' => $job->id]);
        $this->set_status($job, job_manager::STATUS_BLUEPRINTED);
        return true;
    }

    /**
     * Merge all extracted source blocks for the job, in order.
     *
     * @param \stdClass $job The job.
     * @return array{0:array[],1:int} The ordered blocks and their token estimate.
     */
    private function gather_corpus(\stdClass $job): array {
        global $DB;
        $blocks = [];
        $sources = $DB->get_records('coursegen_source', ['jobid' => $job->id], 'id ASC');
        foreach ($sources as $source) {
            if ($source->corpus === null) {
                continue;
            }
            foreach (corpus::from_json($source->corpus)->get_blocks() as $block) {
                $blocks[] = $block;
            }
        }
        $tokens = (int) ceil(\core_text::strlen($this->render_blocks($blocks)) / 4);
        return [$blocks, $tokens];
    }

    /**
     * Render blocks to plain text, marking headings so structure survives.
     *
     * @param array[] $blocks The corpus blocks.
     * @return string
     */
    private function render_blocks(array $blocks): string {
        $lines = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === corpus::TYPE_HEADING) {
                $lines[] = "\n" . str_repeat('#', (int) ($block['level'] ?? 1)) . ' ' . $block['text'];
            } else {
                $lines[] = $block['text'];
            }
        }
        return trim(implode("\n", $lines));
    }

    /**
     * Map-reduce: summarize budget-sized chunks, then concatenate the summaries
     * as the synthesis input. Works on flat, heading-less corpora too.
     *
     * @param array[] $blocks The corpus blocks.
     * @param int $budget The per-call token budget.
     * @param \stdClass $job The job.
     * @param \context $context The job context.
     * @return string|null The reduced source text, or null if a call failed.
     */
    private function map_reduce(array $blocks, int $budget, \stdClass $job, \context $context): ?string {
        $summaries = [];
        foreach ($this->chunk_blocks($blocks, $budget) as $chunktext) {
            $result = $this->call_and_log($this->summary_prompt($chunktext), $job, $context, 'summarize');
            if (!$result->success) {
                $this->set_status($job, job_manager::STATUS_FAILED);
                return null;
            }
            $summaries[] = trim($result->content);
        }
        return implode("\n\n", array_filter($summaries));
    }

    /**
     * Split blocks into chunks whose rendered size stays within the budget.
     *
     * @param array[] $blocks The corpus blocks.
     * @param int $budget The per-call token budget.
     * @return string[] Rendered chunk texts.
     */
    private function chunk_blocks(array $blocks, int $budget): array {
        $maxchars = $budget * 4;
        $chunks = [];
        $current = [];
        $currentchars = 0;
        foreach ($blocks as $block) {
            $blockchars = \core_text::strlen($block['text']);
            if ($current !== [] && ($currentchars + $blockchars) > $maxchars) {
                $chunks[] = $this->render_blocks($current);
                $current = [];
                $currentchars = 0;
            }
            $current[] = $block;
            $currentchars += $blockchars;
        }
        if ($current !== []) {
            $chunks[] = $this->render_blocks($current);
        }
        // Defensive: never feed more than the budget to a single call.
        return array_map(static fn(string $t): string => \core_text::substr($t, 0, $maxchars), $chunks);
    }

    /**
     * Call the reasoning client and write a §10.2 audit-log row.
     *
     * @param string $prompt The prompt.
     * @param \stdClass $job The job.
     * @param \context $context The job context.
     * @param string $detail Non-sensitive note describing the call.
     * @return text_result
     */
    private function call_and_log(string $prompt, \stdClass $job, \context $context, string $detail): text_result {
        global $DB;
        $result = $this->client->generate($prompt, $context, (int) $job->userid);
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id,
            'userid' => $job->userid,
            'stage' => self::STAGE,
            'tier' => self::TIER,
            'actionname' => 'generate_text',
            'provider' => $result->provider,
            'model' => $result->model,
            'tokensin' => $result->prompttokens,
            'tokensout' => $result->completiontokens,
            'imagecount' => null,
            'estimatedcost' => null,
            'outcome' => $result->success ? 'success' : 'failure',
            'detail' => $result->success ? $detail : ($detail . ': ' . $result->error),
            'timecreated' => time(),
        ]);
        return $result;
    }

    /**
     * Set the job status and bump timemodified.
     *
     * @param \stdClass $job The job.
     * @param string $status The new status.
     * @return void
     */
    private function set_status(\stdClass $job, string $status): void {
        global $DB;
        // Update only our own fields so a concurrently-set estimate isn't clobbered.
        $job->status = $status;
        $job->timemodified = time();
        $DB->set_field('coursegen_job', 'status', $status, ['id' => $job->id]);
        $DB->set_field('coursegen_job', 'timemodified', $job->timemodified, ['id' => $job->id]);
    }

    /**
     * Fail the job, logging the reason.
     *
     * @param \stdClass $job The job.
     * @param \context|null $context The job context, if known.
     * @param string $reason Non-sensitive reason.
     * @return void
     */
    private function fail_job(\stdClass $job, ?\context $context, string $reason): void {
        global $DB;
        $this->set_status($job, job_manager::STATUS_FAILED);
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id,
            'userid' => $job->userid,
            'stage' => self::STAGE,
            'tier' => self::TIER,
            'actionname' => null,
            'provider' => null,
            'model' => null,
            'tokensin' => null,
            'tokensout' => null,
            'imagecount' => null,
            'estimatedcost' => null,
            'outcome' => 'failure',
            'detail' => $reason,
            'timecreated' => time(),
        ]);
        mtrace("local_coursegen: blueprint for job {$job->id} failed: {$reason}");
    }

    /**
     * The configured per-call reasoning working budget, in tokens.
     *
     * @return int
     */
    private function budget_tokens(): int {
        return (int) (get_config('local_coursegen', 'reasoning_budget_tokens') ?: self::DEFAULT_BUDGET_TOKENS);
    }

    /**
     * Build the synthesis prompt that asks for the blueprint as strict JSON.
     *
     * @param string $sourcetext The corpus (or reduced summaries).
     * @return string
     */
    private function blueprint_prompt(string $sourcetext): string {
        return <<<PROMPT
You are an instructional designer. From the SOURCE MATERIAL below, design a
structured online course. Respond with ONLY a JSON object (no prose, no code
fences) of exactly this shape:

{
  "title": "course title",
  "description": "one-paragraph course description",
  "sections": [
    {
      "title": "section title",
      "objectives": ["learning objective", "..."],
      "summary": "what this section teaches",
      "image": {"generate": true or false, "prompthint": "diagram idea or empty"},
      "assessment": {"type": "quiz" or "none", "questioncount": integer, "notes": "optional"}
    }
  ]
}

Produce a coherent ordering even if the source is unstructured.

SOURCE MATERIAL:
{$sourcetext}
PROMPT;
    }

    /**
     * Build a chunk-summarization prompt for the map step.
     *
     * @param string $chunktext The chunk text.
     * @return string
     */
    private function summary_prompt(string $chunktext): string {
        return <<<PROMPT
Summarize the following source material concisely, preserving the key topics,
terms, and their order so a course outline can be built from the summary.
Respond with prose only.

SOURCE MATERIAL:
{$chunktext}
PROMPT;
    }
}
