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
 * Materializes an approved blueprint into a hidden format_pathway course.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

use local_coursegen\local\ai\image_client;
use local_coursegen\local\ai\text_client;
use local_coursegen\local\ai\text_result;

/**
 * Stage 5 of the pipeline (SPEC §2): turns an approved blueprint into a real,
 * hidden course in format_pathway (draft-by-default, D3). Each section becomes
 * a pathway section holding one inline "Text and media" area (mod_label, D12)
 * with drafting-tier reading content and, when flagged, an embedded
 * AI-generated image with alt text. Enforces the spend + image caps at
 * materialize-time (SPEC §7), logs every AI call (§10.2), and advances the job
 * approved → materializing → complete/failed. It never publishes the course.
 */
class materializer {
    /** @var string Drafting prompt tier label (internal; D11). */
    private const TIER_DRAFTING = 'drafting';

    /** @var string Image tier label. */
    private const TIER_IMAGE = 'image';

    /** @var string Pipeline stage for the audit log. */
    private const STAGE = 'materialize';

    /**
     * Construct the materializer.
     *
     * @param text_client $textclient Drafting/alt-text client (injectable for tests).
     * @param image_client $imageclient Image client (injectable for tests).
     */
    public function __construct(
        /** @var text_client The text client. */
        private text_client $textclient,
        /** @var image_client The image client. */
        private image_client $imageclient,
    ) {
    }

    /**
     * Materialize an approved job's blueprint into a hidden course.
     *
     * @param \stdClass $job The coursegen_job row (expected status: approved).
     * @return bool True on success; false if the job was failed.
     */
    public function materialize(\stdClass $job): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        if ($job->status !== job_manager::STATUS_APPROVED) {
            return false;
        }
        $blueprint = blueprint_store::load_current($job->id);
        if ($blueprint === null || !$blueprint->is_valid()) {
            $this->fail($job, 'no valid current blueprint');
            return false;
        }
        $context = \context::instance_by_id($job->contextid, IGNORE_MISSING);
        if (!$context instanceof \context_coursecat) {
            $this->fail($job, 'job context is not a category');
            return false;
        }

        // Spend cap pre-check (hard-stop), with a soft warning near the threshold.
        if (!$this->spend_precheck($job, $blueprint->estimate_units())) {
            $this->fail($job, 'estimate exceeds remaining spend cap');
            return false;
        }

        $this->set_status($job, job_manager::STATUS_MATERIALIZING);

        $course = $this->create_hidden_course($job, $blueprint, (int) $context->instanceid);
        $DB->set_field('coursegen_job', 'courseid', $course->id, ['id' => $job->id]);
        $coursecontext = \context_course::instance($course->id);

        $imagebudget = $this->remaining_image_budget();

        foreach ($blueprint->get_sections() as $i => $section) {
            if ($this->spend_exceeded($job)) {
                $this->fail($job, 'spend cap exceeded mid-run');
                return false;
            }

            $sectionnum = $this->add_named_section($course, $section['title']);

            $html = $this->draft_reading($job, $coursecontext, $blueprint, $section);
            $draftid = $this->new_draft_itemid();

            if (!empty($section['image']['generate']) && $imagebudget > 0) {
                $imghtml = $this->generate_and_attach_image($job, $coursecontext, $section, $draftid);
                if ($imghtml !== '') {
                    $html .= $imghtml;
                    $imagebudget--;
                }
            }

            $this->create_label($course, $sectionnum, $html, $draftid);
        }

        $this->set_status($job, job_manager::STATUS_COMPLETE);
        return true;
    }

    /**
     * Create the hidden course in format_pathway with completion enabled.
     *
     * @param \stdClass $job The job.
     * @param blueprint $blueprint The approved blueprint.
     * @param int $categoryid The target category id.
     * @return \stdClass The created course.
     */
    private function create_hidden_course(\stdClass $job, blueprint $blueprint, int $categoryid): \stdClass {
        $data = (object) [
            'category' => $categoryid,
            'fullname' => \core_text::substr($blueprint->get_title(), 0, 254),
            'shortname' => 'coursegen-' . $job->id,
            'summary' => $blueprint->get_description(),
            'summaryformat' => FORMAT_HTML,
            'format' => 'pathway',
            'visible' => 0,
            'enablecompletion' => 1,
            'numsections' => 0,
        ];
        return create_course($data);
    }

    /**
     * Append a named section to the course and return its number.
     *
     * @param \stdClass $course The course.
     * @param string $title The section title.
     * @return int The new section number.
     */
    private function add_named_section(\stdClass $course, string $title): int {
        $section = course_create_section($course);
        course_update_section($course, $section, (object) ['name' => \core_text::substr($title, 0, 250)]);
        return (int) $section->section;
    }

    /**
     * Create the inline Text and media area (mod_label) with manual completion.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @param string $html The intro HTML (content + any embedded image).
     * @param int $draftitemid Draft area holding the embedded image, if any.
     * @return void
     */
    private function create_label(\stdClass $course, int $sectionnum, string $html, int $draftitemid): void {
        global $DB;
        $moduleinfo = (object) [
            'modulename' => 'label',
            'module' => $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST),
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'introeditor' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => $draftitemid],
            // Manual completion so the section counts toward format_pathway progress
            // and the later muprog/mucertify completion chain (D12).
            'completion' => COMPLETION_TRACKING_MANUAL,
            'completionview' => 0,
            'completionexpected' => 0,
            'completiongradeitemnumber' => null,
            'completionpassgrade' => 0,
        ];
        add_moduleinfo($moduleinfo, $course);
    }

    /**
     * Generate reading content (drafting tier) for a section as an HTML fragment.
     *
     * @param \stdClass $job The job.
     * @param \context $context The course context.
     * @param blueprint $blueprint The blueprint (for course context).
     * @param array $section The section spec.
     * @return string The reading HTML (empty string if generation failed).
     */
    private function draft_reading(\stdClass $job, \context $context, blueprint $blueprint, array $section): string {
        $objectives = $section['objectives'] ? implode('; ', $section['objectives']) : '(none stated)';
        $prompt = <<<PROMPT
Write the reading content for one section of the online course
"{$blueprint->get_title()}". Section: "{$section['title']}".
Learning objectives: {$objectives}.
Section summary: {$section['summary']}.
Return a clean HTML fragment (headings, paragraphs, lists) suitable for
embedding directly in a page — no <html>/<head>/<body> wrapper and no code
fences.
PROMPT;
        $result = $this->call_text($job, $context, self::TIER_DRAFTING, 'draft reading: ' . $section['title'], $prompt);
        return $result->success ? $this->strip_fences($result->content) : '';
    }

    /**
     * Generate an image for a flagged section, attach it to a draft area, and
     * return the embedding HTML (with generated alt text). Returns '' on failure.
     *
     * @param \stdClass $job The job.
     * @param \context $context The course context.
     * @param array $section The section spec.
     * @param int $draftitemid The draft area to place the image in.
     * @return string The <img> HTML, or '' on failure.
     */
    private function generate_and_attach_image(\stdClass $job, \context $context, array $section, int $draftitemid): string {
        global $USER;
        $hint = $section['image']['prompthint'] !== '' ? $section['image']['prompthint'] : $section['title'];

        $result = $this->imageclient->generate_image($hint, $context, (int) $job->userid);
        $this->log(
            $job,
            self::TIER_IMAGE,
            'generate_image',
            $result->provider,
            $result->model,
            null,
            null,
            $result->success ? 1 : 0,
            $result->success ? 'image: ' . $section['title'] : 'image failed: ' . $result->error,
            $result->success ? 'success' : 'failure'
        );
        if (!$result->success || $result->draftfile === null) {
            return '';
        }

        // Copy the generated image into a draft area we control, then reference it.
        $fs = get_file_storage();
        $filename = clean_param($result->draftfile->get_filename(), PARAM_FILE) ?: 'image.png';
        $fs->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $result->draftfile);

        $alt = $this->generate_alt_text($job, $context, $section);
        return \html_writer::empty_tag('img', [
            'src' => '@@PLUGINFILE@@/' . $filename,
            'alt' => $alt,
            'class' => 'img-fluid',
        ]);
    }

    /**
     * Generate alt text for a section image (drafting tier).
     *
     * @param \stdClass $job The job.
     * @param \context $context The course context.
     * @param array $section The section spec.
     * @return string Plain-text alt (falls back to the section title).
     */
    private function generate_alt_text(\stdClass $job, \context $context, array $section): string {
        $hint = $section['image']['prompthint'] !== '' ? $section['image']['prompthint'] : $section['title'];
        $prompt = "Write concise (max 120 characters) alt text describing an "
            . "illustrative image for: {$hint}. Respond with the alt text only, no quotes.";
        $result = $this->call_text($job, $context, self::TIER_IMAGE, 'alt text: ' . $section['title'], $prompt);
        $alt = $result->success ? trim(strip_tags($result->content)) : '';
        return $alt !== '' ? \core_text::substr($alt, 0, 250) : $section['title'];
    }

    /**
     * Call the text client and log it (§10.2), accruing actual spend.
     *
     * @param \stdClass $job The job.
     * @param \context $context The course context.
     * @param string $tier The tier label.
     * @param string $detail Non-sensitive note.
     * @param string $prompt The prompt.
     * @return text_result
     */
    private function call_text(\stdClass $job, \context $context, string $tier, string $detail, string $prompt): text_result {
        $result = $this->textclient->generate($prompt, $context, (int) $job->userid);
        $this->log(
            $job,
            $tier,
            'generate_text',
            $result->provider,
            $result->model,
            $result->prompttokens,
            $result->completiontokens,
            null,
            $result->success ? $detail : ($detail . ': ' . $result->error),
            $result->success ? 'success' : 'failure'
        );
        return $result;
    }

    /**
     * Whether the job's estimate fits the tenant's remaining spend budget;
     * logs a soft warning if it would cross the warning threshold.
     *
     * @param \stdClass $job The job.
     * @param int $estimate The job estimate in generation units.
     * @return bool True if within the cap.
     */
    private function spend_precheck(\stdClass $job, int $estimate): bool {
        $cap = (int) (get_config('local_coursegen', 'cap_period_spend') ?: 0);
        if ($cap <= 0) {
            return true; // No cap configured.
        }
        $spent = $this->tenant_spent();
        $threshold = (int) (get_config('local_coursegen', 'warn_threshold_pct') ?: 0);
        if ($threshold > 0 && ($spent + $estimate) >= ($cap * $threshold / 100)) {
            $this->log(
                $job,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                "approaching spend cap: {$spent}+{$estimate} of {$cap}"
            );
        }
        return ($spent + $estimate) <= $cap;
    }

    /**
     * Whether accrued tenant spend has exceeded the cap (mid-run guard).
     *
     * @param \stdClass $job The job.
     * @return bool
     */
    private function spend_exceeded(\stdClass $job): bool {
        $cap = (int) (get_config('local_coursegen', 'cap_period_spend') ?: 0);
        return $cap > 0 && $this->tenant_spent() > $cap;
    }

    /**
     * Tenant spend so far, accumulated from the §10.2 token logs (generation units).
     *
     * @return int
     */
    private function tenant_spent(): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT COALESCE(SUM(COALESCE(tokensin, 0) + COALESCE(tokensout, 0)), 0) FROM {coursegen_log}'
        );
    }

    /**
     * Remaining image sub-cap budget (images used counted from the audit log).
     *
     * @return int
     */
    private function remaining_image_budget(): int {
        global $DB;
        $cap = (int) (get_config('local_coursegen', 'cap_image_count') ?: 0);
        if ($cap <= 0) {
            return PHP_INT_MAX; // No image cap configured.
        }
        $used = (int) $DB->get_field_sql(
            'SELECT COALESCE(SUM(imagecount), 0) FROM {coursegen_log}'
        );
        return max(0, $cap - $used);
    }

    /**
     * A fresh draft item id in the current user's draft area.
     *
     * @return int
     */
    private function new_draft_itemid(): int {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        return file_get_unused_draft_itemid();
    }

    /**
     * Strip Markdown code fences from model HTML output.
     *
     * @param string $html The raw output.
     * @return string
     */
    private function strip_fences(string $html): string {
        $fence = preg_quote(str_repeat(chr(96), 3), '/');
        return trim(preg_replace('/^' . $fence . '(?:html)?\s*|\s*' . $fence . '$/i', '', trim($html)));
    }

    /**
     * Set the job status and bump timemodified (own fields only).
     *
     * @param \stdClass $job The job.
     * @param string $status The new status.
     * @return void
     */
    private function set_status(\stdClass $job, string $status): void {
        global $DB;
        $job->status = $status;
        $DB->set_field('coursegen_job', 'status', $status, ['id' => $job->id]);
        $DB->set_field('coursegen_job', 'timemodified', time(), ['id' => $job->id]);
    }

    /**
     * Fail the job and log the reason.
     *
     * @param \stdClass $job The job.
     * @param string $reason Non-sensitive reason.
     * @return void
     */
    private function fail(\stdClass $job, string $reason): void {
        $this->set_status($job, job_manager::STATUS_FAILED);
        $this->log($job, null, null, null, null, null, null, null, $reason, 'failure');
        mtrace("local_coursegen: materialize job {$job->id} failed: {$reason}");
    }

    /**
     * Write a §10.2 audit row and accrue actual spend onto the job.
     *
     * @param \stdClass $job The job.
     * @param string|null $tier The tier label.
     * @param string|null $actionname The AI action, if any.
     * @param string|null $provider The resolved provider.
     * @param string|null $model The resolved model.
     * @param int|null $tokensin Prompt tokens.
     * @param int|null $tokensout Completion tokens.
     * @param int|null $imagecount Images produced.
     * @param string $detail Non-sensitive note.
     * @param string $outcome 'success' or 'failure'.
     * @return void
     */
    private function log(
        \stdClass $job,
        ?string $tier,
        ?string $actionname,
        ?string $provider,
        ?string $model,
        ?int $tokensin,
        ?int $tokensout,
        ?int $imagecount,
        string $detail,
        string $outcome = 'success'
    ): void {
        global $DB;
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id,
            'userid' => $job->userid,
            'stage' => self::STAGE,
            'tier' => $tier,
            'actionname' => $actionname,
            'provider' => $provider,
            'model' => $model,
            'tokensin' => $tokensin,
            'tokensout' => $tokensout,
            'imagecount' => $imagecount,
            'estimatedcost' => null,
            'outcome' => $outcome,
            'detail' => $detail,
            'timecreated' => time(),
        ]);
        if ($tokensin !== null || $tokensout !== null) {
            $DB->set_field('coursegen_job', 'actualspend', $this->job_spent($job), ['id' => $job->id]);
        }
    }

    /**
     * Total generation units accrued by this job (from its audit log).
     *
     * @param \stdClass $job The job.
     * @return int
     */
    private function job_spent(\stdClass $job): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT COALESCE(SUM(COALESCE(tokensin, 0) + COALESCE(tokensout, 0)), 0)
               FROM {coursegen_log} WHERE jobid = :jobid',
            ['jobid' => $job->id]
        );
    }
}
