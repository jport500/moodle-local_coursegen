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
 * Regenerates a single blueprint section via the reasoning tier.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

use local_coursegen\local\ai\text_client;
use local_coursegen\local\ai\text_result;

/**
 * Re-calls the reasoning tier (through the P2 text_client seam) for one section
 * of the current blueprint, with the same JSON tolerance and failure handling
 * as full generation. A success stores a new blueprint version, recomputes the
 * estimate, logs the call (§10.2) and reopens review if the job was approved.
 */
class section_regenerator {
    /** @var string Pipeline stage for the audit log (a reasoning call). */
    private const STAGE = 'blueprint';

    /** @var string Capability tier used. */
    private const TIER = 'reasoning';

    /**
     * Construct the regenerator.
     *
     * @param text_client $client The reasoning-tier text client (injectable for tests).
     */
    public function __construct(
        /** @var text_client The reasoning-tier text client. */
        private text_client $client,
    ) {
    }

    /**
     * Regenerate one section (0-based index) of the job's current blueprint.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param int $index The section index to regenerate.
     * @param int $userid The user requesting regeneration.
     * @return bool True if a new version was stored; false on any failure.
     */
    public function regenerate(\stdClass $job, int $index, int $userid): bool {
        global $DB;

        $blueprint = blueprint_store::load_current($job->id);
        if ($blueprint === null) {
            return false;
        }
        $sections = $blueprint->get_sections();
        if (!isset($sections[$index])) {
            return false;
        }
        $context = \context::instance_by_id($job->contextid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        // Don't spend reasoning tokens for a tenant already over its period cap.
        if (spend_governor::over_spend_cap()) {
            audit_log::record(
                (int) $job->id,
                $userid,
                self::STAGE,
                audit_log::FAILURE,
                "regenerate section {$index} refused: period spend cap reached",
            );
            return false;
        }

        $result = $this->client->generate(
            $this->section_prompt($blueprint, $sections[$index]),
            $context,
            $userid,
        );
        $this->log($job, $userid, $result, "regenerate section {$index}");
        if (!$result->success) {
            return false;
        }

        $decoded = blueprint::decode_object($result->content);
        if ($decoded === null) {
            return false;
        }
        $sectiondata = $decoded['section'] ?? $decoded;
        if (!is_array($sectiondata) || trim((string) ($sectiondata['title'] ?? '')) === '') {
            return false;
        }

        // Rebuild the blueprint with the regenerated section swapped in.
        $rebuilt = new blueprint();
        $rebuilt->set_title($blueprint->get_title());
        $rebuilt->set_description($blueprint->get_description());
        foreach ($sections as $i => $section) {
            $rebuilt->add_section($i === $index ? $sectiondata : $section);
        }

        blueprint_store::save_new_version($job, $rebuilt, $userid);
        $DB->set_field('coursegen_job', 'estimatedspend', $rebuilt->estimate_units(), ['id' => $job->id]);
        review_gate::reopen_if_approved($job, $userid);
        return true;
    }

    /**
     * Audit-log a regeneration call (§10.2).
     *
     * @param \stdClass $job The job.
     * @param int $userid The user.
     * @param text_result $result The call result.
     * @param string $detail Non-sensitive note.
     * @return void
     */
    private function log(\stdClass $job, int $userid, text_result $result, string $detail): void {
        audit_log::record(
            (int) $job->id,
            $userid,
            self::STAGE,
            $result->success ? audit_log::SUCCESS : audit_log::FAILURE,
            $result->success ? $detail : ($detail . ': ' . $result->error),
            [
                'tier' => self::TIER,
                'actionname' => 'generate_text',
                'provider' => $result->provider,
                'model' => $result->model,
                'tokensin' => $result->prompttokens,
                'tokensout' => $result->completiontokens,
            ]
        );
    }

    /**
     * Build the prompt asking for a single replacement section as strict JSON.
     *
     * @param blueprint $blueprint The current blueprint (for course context).
     * @param array $section The section being replaced.
     * @return string
     */
    private function section_prompt(blueprint $blueprint, array $section): string {
        $coursetitle = $blueprint->get_title();
        $sectiontitle = $section['title'];
        return <<<PROMPT
You are an instructional designer revising one section of the course
"{$coursetitle}". Regenerate the section currently titled "{$sectiontitle}".
Respond with ONLY a JSON object (no prose, no code fences) of this shape:

{
  "title": "section title",
  "objectives": ["learning objective", "..."],
  "summary": "what this section teaches",
  "image": {"generate": true or false, "prompthint": "diagram idea or empty"},
  "assessment": {"type": "quiz" or "none", "questioncount": integer, "notes": "optional"}
}
PROMPT;
    }
}
