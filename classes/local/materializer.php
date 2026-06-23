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
use local_coursegen\local\ai\quiz_client;
use local_coursegen\local\ai\text_client;
use local_coursegen\local\ai\text_result;

/**
 * Stage 5 of the pipeline (SPEC §2): turns an approved blueprint into a real,
 * hidden course in format_pathway (draft-by-default, D3). Each section becomes
 * a pathway section holding one inline "Text and media" area (mod_label, D12)
 * with drafting-tier reading content and, when flagged, an embedded
 * AI-generated image with alt text; sections whose assessment spec is a quiz
 * also get a (stealth) mod_knowledgecheck with quizgenpro-delegated questions,
 * rendered inline via its filter token (D5/D10/D15). Enforces the spend +
 * image caps at materialize-time (SPEC §7), logs every AI call (§10.2), and
 * advances the job approved → materializing → complete/failed. It never
 * publishes the course. The pass is re-entrant: any half-built hidden course
 * from a prior attempt is deleted before rebuilding, so a retry never mints a
 * second course or strands the job at "materializing".
 */
class materializer {
    /** @var string Drafting prompt tier label (internal; D11). */
    private const TIER_DRAFTING = 'drafting';

    /** @var string Image tier label. */
    private const TIER_IMAGE = 'image';

    /** @var string Assessment tier label. */
    private const TIER_ASSESSMENT = 'assessment';

    /** @var string Pipeline stage for the audit log. */
    private const STAGE = 'materialize';

    /** @var int Default question count when an assessed section sets none. */
    private const DEFAULT_QUESTION_COUNT = 5;

    /** @var int Maximum grade for a generated graded quiz (D23). */
    private const QUIZ_MAX_GRADE = 100;

    /** @var int Grade-to-pass for a generated graded quiz; completion gates on it (D23). */
    private const QUIZ_PASS_GRADE = 50;

    /** @var \context|null The course's default question-bank context, cached per run. */
    private ?\context $coursebankcontext = null;

    /**
     * Construct the materializer.
     *
     * @param text_client $textclient Drafting/alt-text client (injectable for tests).
     * @param image_client $imageclient Image client (injectable for tests).
     * @param quiz_client $quizclient Quiz-question client (injectable for tests).
     */
    public function __construct(
        /** @var text_client The text client. */
        private text_client $textclient,
        /** @var image_client The image client. */
        private image_client $imageclient,
        /** @var quiz_client The quiz client. */
        private quiz_client $quizclient,
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
        $this->coursebankcontext = null; // Fresh per course (the prior one may be deleted).

        // Accept a fresh approval or a retry of an attempt that died mid-run.
        if (
            $job->status !== job_manager::STATUS_APPROVED
                && $job->status !== job_manager::STATUS_MATERIALIZING
        ) {
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

        // Refuse rather than destroy the course's live learner state on a
        // re-materialize (D20). Runs BEFORE any cleanup, so a refusal leaves the
        // existing course fully intact.
        $reason = self::course_learner_state_reason(isset($job->courseid) ? (int) $job->courseid : null);
        if ($reason !== null) {
            $this->refuse(
                $job,
                'Re-materialize refused: rebuilding would destroy live learner state — '
                    . $reason
                    . '. Unenrol or migrate the affected learners before editing and re-approving'
                    . ' to rebuild. The existing course is left live and unchanged.'
            );
            return false;
        }

        // Drop any half-built course from a prior attempt before rebuilding.
        $this->cleanup_partial_course($job);

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

        // Introduction bookend: section 0 (pathway's native Overview), untracked (D25).
        $this->build_intro_section($course, $coursecontext, $blueprint);

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

            // Build the section's assessment, if any. $assessmentbuilt tracks whether
            // an activity actually got created (vs a gen/banking skip, D14), so the
            // reading label can carry completion only when nothing else does.
            // A knowledge check is built before the label so its filter token can be
            // embedded in the label HTML; $token is null when no check was built, ''
            // when built without an inline token (filter disabled), else the token.
            $assesstype = $section['assessment']['type'] ?? '';
            $assessmentbuilt = false;
            if ($assesstype === blueprint::ASSESS_KNOWLEDGECHECK) {
                $token = $this->build_knowledgecheck($job, $course, $coursecontext, $sectionnum, $html, $section);
                $assessmentbuilt = ($token !== null);
                if ($token !== null && $token !== '') {
                    $html .= "\n" . \html_writer::tag('p', $token);
                }
            } else if ($assesstype === blueprint::ASSESS_QUIZ) {
                // A graded quiz is a separate, visible click-through activity (D23).
                $assessmentbuilt = $this->build_quiz($job, $course, $coursecontext, $sectionnum, $html, $section);
            }

            // Exactly one completion-tracked activity per section (D21/D23): when an
            // assessment was built it is the signal (label untracked); otherwise the
            // reading label carries the manual signal so the section stays completable.
            $labelcompletion = $assessmentbuilt ? COMPLETION_TRACKING_NONE : COMPLETION_TRACKING_MANUAL;
            $labelcmid = $this->create_label($course, $sectionnum, $html, $draftid, $labelcompletion);

            // The assessment is built before the label (its completion outcome and any
            // inline filter token must be known first), so it lands first in the
            // section. Put the reading ahead of it so a visible assessment (a graded
            // quiz, or a non-stealth knowledge check) sits at the END of the section.
            // A stealth knowledge check is off the course page, so this only reorders
            // the sequence, not what the learner sees (it stays inline in the reading).
            if ($assessmentbuilt) {
                $this->move_reading_to_front($course, $sectionnum, $labelcmid);
            }
        }

        // Wrap-up bookend: the last section, untracked (D25) — closure and a
        // <Next> target, and an obvious home for an operator-added certificate.
        $this->build_final_section($course);

        // Decorative course thumbnail, gated by the same image opt-in the sections
        // use (a flagged section) and the image sub-cap; skipped, not failed, when
        // off or exhausted (D25).
        if ($blueprint->image_count() > 0 && $imagebudget > 0) {
            $this->generate_course_thumbnail($job, $course, $coursecontext, $blueprint);
        }

        // Optional AI intro header banner on section 0 (D36), opt-in per job, gated
        // by the image sub-cap (live, so the thumbnail above is already counted).
        // Best-effort: never fails the build.
        if (!empty($job->headerbanner) && spend_governor::image_remaining() > 0) {
            $this->generate_intro_banner($job, $course, $coursecontext, $blueprint);
        }

        // Require completion of every tracked activity so course completion fires
        // (D22). The untracked bookends contribute nothing. Runs after all
        // activities exist.
        self::configure_course_completion((int) $course->id);

        // Clear the orphan flag as the deliberate LAST step of a successful build
        // (D31). A re-materialize ran cleanup_partial_course earlier, whose
        // delete_course() fired course_deleted — and our synchronous observer set
        // timecoursedeleted and nulled courseid on this very job. Clearing it here,
        // after that event has fired and after courseid was re-set (:145), makes
        // the clear reliably win the race so a rebuilt job is not left mis-flagged
        // as "course deleted".
        $DB->set_field('coursegen_job', 'timecoursedeleted', null, ['id' => $job->id]);

        $this->set_status($job, job_manager::STATUS_COMPLETE);
        return true;
    }

    /**
     * Configure course-completion criteria on a generated course: require every
     * completion-tracked activity, aggregated with ALL (D22). P14 leaves exactly
     * one tracked activity per section, so this is "completed every section".
     *
     * Mirrors core's course/completion.php. Shared with the completion walkthrough
     * test so it asserts against the real production wiring. A clean re-materialize
     * deletes the prior course (and its criteria) and rebuilds these fresh, so no
     * criterion ever points at a stale cmid.
     *
     * @param int $courseid The generated course id.
     * @return void
     */
    public static function configure_course_completion(int $courseid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        require_once($CFG->dirroot . '/completion/completion_aggregation.php');

        $cmids = $DB->get_fieldset_select(
            'course_modules',
            'id',
            'course = :course AND completion <> :none AND deletioninprogress = 0',
            ['course' => $courseid, 'none' => COMPLETION_TRACKING_NONE]
        );
        if (!$cmids) {
            return; // No tracked activities to require (degenerate; should not occur).
        }

        // Overall and activity aggregation = ALL (every tracked activity required).
        foreach ([null, COMPLETION_CRITERIA_TYPE_ACTIVITY] as $criteriatype) {
            $aggregation = new \completion_aggregation(['course' => $courseid, 'criteriatype' => $criteriatype]);
            $aggregation->setMethod(COMPLETION_AGGREGATION_ALL);
            $aggregation->save();
        }

        $criterion = new \completion_criteria_activity();
        $data = (object) [
            'id' => $courseid,
            'criteria_activity' => array_fill_keys(array_map('intval', $cmids), 1),
        ];
        $criterion->update_config($data);
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
     * Create the inline Text and media area (mod_label).
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @param string $html The intro HTML (content + any embedded image).
     * @param int $draftitemid Draft area holding the embedded image, if any.
     * @param int $completion COMPLETION_TRACKING_MANUAL when the label is the
     *        section's completion signal, or COMPLETION_TRACKING_NONE when a
     *        knowledge check/quiz carries it instead (D21) or for an untracked
     *        intro/wrap-up bookend (D25).
     * @return int The created label's course-module id.
     */
    private function create_label(
        \stdClass $course,
        int $sectionnum,
        string $html,
        int $draftitemid,
        int $completion
    ): int {
        global $DB;
        $moduleinfo = (object) [
            'modulename' => 'label',
            'module' => $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST),
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'introeditor' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => $draftitemid],
            'completion' => $completion,
            'completionview' => 0,
            'completionexpected' => 0,
            'completiongradeitemnumber' => null,
            'completionpassgrade' => 0,
        ];
        return (int) add_moduleinfo($moduleinfo, $course)->coursemodule;
    }

    /**
     * Move the reading label to the front of its section so the section's
     * assessment activity sits at the END, after the reading. The assessment is
     * created before the label (its completion outcome and inline token must be
     * resolved first), which would otherwise place it first. Only the reading and
     * one assessment occupy a content section, so moving the reading ahead of the
     * current first module is sufficient.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The content section number.
     * @param int $labelcmid The reading label's course-module id.
     * @return void
     */
    private function move_reading_to_front(\stdClass $course, int $sectionnum, int $labelcmid): void {
        $cmids = get_fast_modinfo($course)->get_sections()[$sectionnum] ?? [];
        if (count($cmids) < 2 || (int) $cmids[0] === $labelcmid) {
            return;
        }
        // Move the reading before the current first module (the assessment), so the
        // assessment ends up last. cmactions is the 5.2 replacement for moveto_module.
        (new \core_courseformat\local\cmactions($course))->move_before($labelcmid, (int) $cmids[0]);
    }

    /**
     * Build the introduction bookend in SECTION 0 — format_pathway's native
     * "Overview" that a learner lands on first (D25, corrected). Holds a course
     * overview derived (no extra AI call) from the editable course description
     * plus a "what you'll cover" list of the content section titles. Names
     * section 0, pins it in the sidebar via the pathwayshowsection0 format option
     * (set explicitly so it renders the same on any tenant, regardless of the
     * tenant default), and carries an UNTRACKED label — orientation, not a
     * learning unit — so it is never part of the course-completion criteria. The
     * earlier P19 approach added a NUMBERED "Introduction" on top of the native
     * section-0 "Introduction", which rendered to learners as a duplicate.
     *
     * @param \stdClass $course The course.
     * @param \context $coursecontext The course context.
     * @param blueprint $blueprint The approved blueprint.
     * @return void
     */
    private function build_intro_section(\stdClass $course, \context $coursecontext, blueprint $blueprint): void {
        global $DB;

        // Name section 0 and pin it in the pathway sidebar so the Introduction is
        // a visible, returnable nav item (and renders the same on any tenant).
        $section0 = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], '*', MUST_EXIST);
        course_update_section(
            $course,
            $section0,
            (object) ['name' => get_string('introsection_name', 'local_coursegen')]
        );
        course_get_format($course)->update_course_format_options((object) ['pathwayshowsection0' => '1']);

        $html = '';
        if ($blueprint->get_description() !== '') {
            $html .= format_text($blueprint->get_description(), FORMAT_PLAIN, ['context' => $coursecontext]);
        }
        $items = [];
        foreach ($blueprint->get_sections() as $section) {
            if ($section['title'] !== '') {
                $items[] = \html_writer::tag('li', s($section['title']));
            }
        }
        if ($items) {
            $html .= \html_writer::tag('h4', get_string('introsection_covers', 'local_coursegen'));
            $html .= \html_writer::tag('ul', implode('', $items));
        }

        $this->create_label($course, 0, $html, $this->new_draft_itemid(), COMPLETION_TRACKING_NONE);
    }

    /**
     * Build the wrap-up bookend: a short closing section (boilerplate text). Its
     * label is UNTRACKED (D25). The plugin builds the section only — it does not
     * create a certificate and takes no dependency on mod_coursecertificate; an
     * operator may add one here.
     *
     * @param \stdClass $course The course.
     * @return void
     */
    private function build_final_section(\stdClass $course): void {
        $sectionnum = $this->add_named_section($course, get_string('finalsection_name', 'local_coursegen'));
        $html = \html_writer::tag('p', get_string('finalsection_body', 'local_coursegen'));
        $this->create_label($course, $sectionnum, $html, $this->new_draft_itemid(), COMPLETION_TRACKING_NONE);
    }

    /**
     * Generate a decorative course cover image and set it as the course's "Course
     * image" (overviewfiles). Best-effort and skip-not-fail: a generation failure
     * is logged and the build continues. Counts as one image against the sub-cap
     * (the caller gates on opt-in + remaining budget). No alt text — decorative.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $course The course.
     * @param \context $coursecontext The course context.
     * @param blueprint $blueprint The blueprint (for the prompt).
     * @return void
     */
    private function generate_course_thumbnail(
        \stdClass $job,
        \stdClass $course,
        \context $coursecontext,
        blueprint $blueprint
    ): void {
        $prompt = 'A clean, professional cover illustration for an online course titled "'
            . $blueprint->get_title() . '". ' . $blueprint->get_description();
        $result = $this->imageclient->generate_image($prompt, $coursecontext, (int) $job->userid);
        $this->log(
            $job,
            self::TIER_IMAGE,
            'generate_image',
            $result->provider,
            $result->model,
            null,
            null,
            $result->success ? 1 : 0,
            $result->success ? 'course thumbnail' : 'course thumbnail failed: ' . $result->error,
            $result->success ? 'success' : 'failure'
        );
        if (!$result->success || $result->draftfile === null) {
            return;
        }

        $fs = get_file_storage();
        $fs->delete_area_files($coursecontext->id, 'course', 'overviewfiles', 0);
        $filename = clean_param($result->draftfile->get_filename(), PARAM_FILE) ?: 'cover.png';
        $fs->create_file_from_storedfile([
            'contextid' => $coursecontext->id,
            'component' => 'course',
            'filearea' => 'overviewfiles',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $result->draftfile);
    }

    /**
     * Generate the optional AI intro header banner and set it as format_pathway's
     * section-0 header image (D36). Best-effort and skip-not-fail: a generation,
     * file-write, or format-option failure is logged and the build continues — the
     * course must never fail to build because the banner couldn't be set. Counts as
     * one image against the sub-cap (the caller gates on opt-in + remaining budget).
     *
     * Writes into format_pathway's own section-image filearea (course context,
     * component 'format_pathway', filearea 'sectionimage', itemid = section-0 DB id)
     * under a fresh unique filename (cache-bust hygiene, D34), and turns on the
     * course-level pathwayshowimages option explicitly so a tenant default of '0'
     * can't silently hide the banner (the D25 lesson).
     *
     * @param \stdClass $job The job.
     * @param \stdClass $course The course.
     * @param \context $coursecontext The course context.
     * @param blueprint $blueprint The blueprint (for the title).
     * @return void
     */
    private function generate_intro_banner(
        \stdClass $job,
        \stdClass $course,
        \context $coursecontext,
        blueprint $blueprint
    ): void {
        global $DB;
        $result = $this->imageclient->generate_image(
            self::banner_prompt($blueprint->get_title()),
            $coursecontext,
            (int) $job->userid,
            'landscape'
        );
        $this->log(
            $job,
            self::TIER_IMAGE,
            'generate_image',
            $result->provider,
            $result->model,
            null,
            null,
            $result->success ? 1 : 0,
            $result->success ? 'intro header banner' : 'intro header banner failed: ' . $result->error,
            $result->success ? 'success' : 'failure'
        );
        if (!$result->success || $result->draftfile === null) {
            return;
        }

        // Best-effort: swallow any failure of the cross-plugin file write or the
        // format-option set, so the course still materializes fine without the
        // banner. Deliberately broad — any error here must not fail the build.
        try {
            self::write_section0_banner($course, $coursecontext, $result->draftfile);
        } catch (\Throwable $e) {
            $this->log(
                $job,
                self::TIER_IMAGE,
                null,
                null,
                null,
                null,
                null,
                0,
                'intro header banner not set: ' . $e->getMessage(),
                audit_log::FAILURE
            );
        }
    }

    /**
     * The shared MECHANISM (D36) for placing a banner into format_pathway's
     * section-0 header-image filearea: delete the old file, write the new one
     * under a fresh unique filename (cache-bust hygiene, D34), and turn on the
     * course-level pathwayshowimages option (the D25 lesson). Couples to
     * format_pathway's conventional, backup/restore-participating filearea
     * (course context, component 'format_pathway', filearea 'sectionimage',
     * itemid = section-0 DB id). Mechanism only — the caller handles best-effort.
     *
     * @param \stdClass $course The course.
     * @param \context $coursecontext The course context.
     * @param \stored_file $draftfile The generated banner image.
     * @return void
     */
    public static function write_section0_banner(
        \stdClass $course,
        \context $coursecontext,
        \stored_file $draftfile
    ): void {
        global $DB;
        $section0id = (int) $DB->get_field(
            'course_sections',
            'id',
            ['course' => $course->id, 'section' => 0],
            MUST_EXIST
        );
        $ext = pathinfo($draftfile->get_filename(), PATHINFO_EXTENSION) ?: 'png';
        $fs = get_file_storage();
        $fs->delete_area_files($coursecontext->id, 'format_pathway', 'sectionimage', $section0id);
        $fs->create_file_from_storedfile([
            'contextid' => $coursecontext->id,
            'component' => 'format_pathway',
            'filearea' => 'sectionimage',
            'itemid' => $section0id,
            'filepath' => '/',
            'filename' => bin2hex(random_bytes(8)) . '.' . $ext,
        ], $draftfile);
        course_get_format($course)->update_course_format_options((object) ['pathwayshowimages' => '1']);
    }

    /**
     * Whether section 0 currently has a format_pathway header-image file (D36) —
     * used to offer and validate banner regeneration.
     *
     * @param int $courseid The course id.
     * @return bool
     */
    public static function section0_has_banner(int $courseid): bool {
        global $DB;
        if (empty($courseid) || !$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }
        $section0id = (int) $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => 0]);
        if (!$section0id) {
            return false;
        }
        return (bool) get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id,
            'format_pathway',
            'sectionimage',
            $section0id,
            'id',
            false
        );
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
        // The audience pitch lives here, in the reading prose (D26 Fix 2).
        $pitch = course_depth::reading_pitch($job->audiencelevel ?? null);
        $prompt = <<<PROMPT
Write the reading content for one section of the online course
"{$blueprint->get_title()}". Section: "{$section['title']}".
Learning objectives: {$objectives}.
Section summary: {$section['summary']}.

Audience: {$pitch}

Return a clean HTML fragment (headings, paragraphs, lists) suitable for
embedding directly in a page — no <html>/<head>/<body> wrapper and no code
fences.
PROMPT;
        $result = $this->call_text($job, $context, self::TIER_DRAFTING, 'draft reading: ' . $section['title'], $prompt);
        return $result->success ? $this->strip_fences($result->content) : '';
    }

    /**
     * Wrap a section image hint so the image model produces a clean, text-free
     * illustration rather than a labeled infographic — the bare hint (and
     * "diagram"-flavoured hints) drove garbled, truncated multi-column outputs
     * (D30). The image model garbles rendered text, so we steer away from any text
     * or charts. Shared with the image regenerator (D33) so the wording can't drift.
     *
     * @param string $hint The section image hint (or title fallback).
     * @return string The wrapped image prompt.
     */
    public static function section_image_prompt(string $hint): string {
        return "A clean, professional illustration of {$hint}. Illustrative or "
            . "photographic style, depicting the subject. No text, no words, no letters, "
            . "no labels, no captions, and no charts, diagrams, or infographics.";
    }

    /**
     * The intro title-banner prompt (D36) — the OPPOSITE of section_image_prompt:
     * a wide header banner that WANTS the course title rendered as clean, legible
     * text. AI text rendering is imperfect, which is why the banner is regenerable.
     * Shared with the banner regenerator so the wording can't drift.
     *
     * @param string $title The course title.
     * @return string
     */
    public static function banner_prompt(string $title): string {
        return "A wide, professional title banner for an online course. Render the course "
            . "title \"{$title}\" as large, clean, clearly legible text, centered, spelled "
            . "exactly, over a simple complementary background suited to the subject. A "
            . "horizontal header banner — uncluttered, high-contrast, easy to read.";
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

        $result = $this->imageclient->generate_image(
            self::section_image_prompt($hint),
            $context,
            (int) $job->userid
        );
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
        if (spend_governor::crosses_warn_threshold($estimate)) {
            $this->log(
                $job,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                'approaching spend cap: ' . spend_governor::period_spent()
                    . "+{$estimate} of " . spend_governor::spend_cap(),
                audit_log::SUCCESS
            );
        }
        return !spend_governor::would_exceed($estimate);
    }

    /**
     * Whether accrued tenant spend has exceeded the cap (mid-run guard).
     *
     * @param \stdClass $job The job.
     * @return bool
     */
    private function spend_exceeded(\stdClass $job): bool {
        return spend_governor::over_spend_cap();
    }

    /**
     * Remaining image sub-cap budget for this period (0 cap = unlimited).
     *
     * @return int
     */
    private function remaining_image_budget(): int {
        return spend_governor::image_remaining();
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
     * Build a knowledge check for a section: delegate question generation to
     * quizgenpro, bank the questions, place a knowledge check, pin the questions,
     * and return its filter token for embedding in the section label. On any
     * failure the check is skipped (the section keeps its reading) and the course
     * still completes (the image-subcap "skip and build" precedent, D14). When
     * filter_knowledgecheck is disabled the check is created non-stealth (shown on
     * the course page) with no token, so it is never a silently-invisible
     * assessment.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $course The course.
     * @param \context $coursecontext The course context.
     * @param int $sectionnum The section number.
     * @param string $readinghtml The section reading HTML (question source content).
     * @param array $section The section spec.
     * @return string|null The {knowledgecheck ...} token to embed; '' when a check
     *         was built without an inline token (filter disabled); null when no
     *         check was built (generation/banking skipped) so the caller keeps the
     *         reading label as the section's completion signal (D21).
     */
    private function build_knowledgecheck(
        \stdClass $job,
        \stdClass $course,
        \context $coursecontext,
        int $sectionnum,
        string $readinghtml,
        array $section
    ): ?string {
        global $DB;
        $count = ((int) ($section['assessment']['questioncount'] ?? 0)) ?: self::DEFAULT_QUESTION_COUNT;
        $content = trim(html_to_text($readinghtml, 0));
        if ($content === '') {
            $content = $section['title'] . '. ' . $section['summary'];
        }

        $questions = $this->quizclient->generate_questions($content, $count, $coursecontext);
        if ($questions === []) {
            $this->log(
                $job,
                self::TIER_ASSESSMENT,
                'quizgenpro',
                null,
                null,
                null,
                null,
                null,
                'knowledge check skipped (no questions): ' . $section['title'],
                audit_log::FAILURE
            );
            return null;
        }

        $qrefs = $this->bank_questions($questions, $course);
        if ($qrefs === []) {
            $this->log(
                $job,
                self::TIER_ASSESSMENT,
                'quizgenpro',
                null,
                null,
                null,
                null,
                null,
                'knowledge check skipped (banking failed): ' . $section['title'],
                audit_log::FAILURE
            );
            return null;
        }

        $filteron = $this->filter_enabled($coursecontext);
        $kc = $this->create_knowledgecheck($course, $sectionnum, $section['title'], $filteron);
        foreach ($qrefs as [$qbeid, $version]) {
            \mod_knowledgecheck\local\questions::add((int) $kc->instance, $qbeid, $version);
        }
        $n = count($qrefs);

        if ($filteron) {
            $uuid = $DB->get_field('knowledgecheck', 'uuid', ['id' => $kc->instance], MUST_EXIST);
            $this->log(
                $job,
                self::TIER_ASSESSMENT,
                'quizgenpro',
                null,
                null,
                null,
                null,
                null,
                "knowledge check: {$section['title']} ({$n} questions)",
                audit_log::SUCCESS
            );
            return '{knowledgecheck id=' . $uuid . '}';
        }

        // The filter is disabled: non-stealth fallback, no inline token.
        $this->log(
            $job,
            self::TIER_ASSESSMENT,
            'quizgenpro',
            null,
            null,
            null,
            null,
            null,
            "knowledge check (filter disabled — shown on course page): {$section['title']} ({$n} questions)",
            audit_log::SUCCESS
        );
        return '';
    }

    /**
     * Create a knowledge check in a section, completed on a finished attempt so
     * it counts toward format_pathway progress and the cert/CE chain (D12, D15).
     * Stealth (available but off the course page) when the filter is enabled so it
     * renders inline via the label token; otherwise shown on the course page.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @param string $title The section title (used for the activity name).
     * @param bool $stealth Whether to hide it from the course page.
     * @return \stdClass The created module info (carries ->instance).
     */
    private function create_knowledgecheck(
        \stdClass $course,
        int $sectionnum,
        string $title,
        bool $stealth
    ): \stdClass {
        global $DB;
        $moduleinfo = (object) [
            'modulename' => 'knowledgecheck',
            'module' => $DB->get_field('modules', 'id', ['name' => 'knowledgecheck'], MUST_EXIST),
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'visibleoncoursepage' => $stealth ? 0 : 1,
            'cmidnumber' => '',
            'name' => \core_text::substr(get_string('kcname', 'local_coursegen', $title), 0, 250),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            // Automatic completion once the learner finishes an attempt.
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'completionview' => 0,
            'completionexpected' => 0,
            'completiongradeitemnumber' => null,
            'completionpassgrade' => 0,
        ];
        return add_moduleinfo($moduleinfo, $course);
    }

    /**
     * Build a graded, summative quiz for a section (D23): generate and bank
     * questions via the same quizgenpro seam the knowledge check uses, create a
     * separate visible mod_quiz, add the banked questions, and gate completion on
     * passing. On any generation/banking failure the quiz is skipped and the
     * section keeps its reading (D14). The quiz is a click-through activity — a
     * graded exam has no inline render.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $course The course.
     * @param \context $coursecontext The course context.
     * @param int $sectionnum The section number.
     * @param string $readinghtml The section reading HTML (question source content).
     * @param array $section The section spec.
     * @return bool True if a quiz was built; false if it was skipped.
     */
    private function build_quiz(
        \stdClass $job,
        \stdClass $course,
        \context $coursecontext,
        int $sectionnum,
        string $readinghtml,
        array $section
    ): bool {
        $count = ((int) ($section['assessment']['questioncount'] ?? 0)) ?: self::DEFAULT_QUESTION_COUNT;
        $content = trim(html_to_text($readinghtml, 0));
        if ($content === '') {
            $content = $section['title'] . '. ' . $section['summary'];
        }

        $questions = $this->quizclient->generate_questions($content, $count, $coursecontext);
        if ($questions === []) {
            $this->log(
                $job,
                self::TIER_ASSESSMENT,
                'quizgenpro',
                null,
                null,
                null,
                null,
                null,
                'quiz skipped (no questions): ' . $section['title'],
                audit_log::FAILURE
            );
            return false;
        }
        $qrefs = $this->bank_questions($questions, $course);
        if ($qrefs === []) {
            $this->log(
                $job,
                self::TIER_ASSESSMENT,
                'quizgenpro',
                null,
                null,
                null,
                null,
                null,
                'quiz skipped (banking failed): ' . $section['title'],
                audit_log::FAILURE
            );
            return false;
        }

        $quiz = $this->create_quiz($course, $sectionnum, $section['title']);
        $this->add_quiz_questions($quiz, $qrefs);
        \mod_quiz\quiz_settings::create((int) $quiz->instance)->get_grade_calculator()->recompute_quiz_sumgrades();

        $this->log(
            $job,
            self::TIER_ASSESSMENT,
            'quizgenpro',
            null,
            null,
            null,
            null,
            null,
            "graded quiz: {$section['title']} (" . count($qrefs) . ' questions, pass '
            . self::QUIZ_PASS_GRADE . '/' . self::QUIZ_MAX_GRADE . ')',
            audit_log::SUCCESS
        );
        return true;
    }

    /**
     * Create a graded mod_quiz with pass-based automatic completion (D23). The
     * grade-to-pass is written onto the grade item by add_moduleinfo, so a passing
     * attempt yields COMPLETION_COMPLETE_PASS (which completes the course) and a
     * failing one COMPLETION_COMPLETE_FAIL (which does not).
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @param string $title The section title.
     * @return \stdClass The created module info (carries ->instance).
     */
    private function create_quiz(\stdClass $course, int $sectionnum, string $title): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/lib.php'); // QUIZ_GRADEHIGHEST, QUIZ_NAVMETHOD_FREE.
        $moduleinfo = (object) array_merge([
            'modulename' => 'quiz',
            'module' => $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST),
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'name' => \core_text::substr(get_string('quizname', 'local_coursegen', $title), 0, 250),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            // Behaviour and access (the fragile bundle — explicit defaults, 5.2).
            'preferredbehaviour' => 'deferredfeedback',
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '-',
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            // Attempts and grading: unlimited attempts (failing blocks completion,
            // so a learner can retake to pass), highest grade (D23).
            'attempts' => 0,
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'grade' => self::QUIZ_MAX_GRADE,
            'sumgrades' => 0,
            'questionsperpage' => 1,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'shuffleanswers' => 1,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'delay1' => 0,
            'delay2' => 0,
            // Pass-based automatic completion (D23): gradepass drives the grade item.
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionattemptsexhausted' => 0,
            'completionminattempts' => 0,
            'gradepass' => self::QUIZ_PASS_GRADE,
            'completiongradeitemnumber' => 0,
        ], self::quiz_review_options());
        return add_moduleinfo($moduleinfo, $course);
    }

    /**
     * The quiz review-options grid (8 options × 4 timings) that quiz_add_instance
     * folds into the review bitmask columns. Mirrors the mod_form defaults: full
     * review after the attempt and once closed, no during-attempt review.
     *
     * @return array<string, int>
     */
    private static function quiz_review_options(): array {
        $options = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback',
            'generalfeedback', 'rightanswer', 'overallfeedback'];
        $grid = [];
        foreach ($options as $option) {
            $grid[$option . 'during'] = ($option === 'attempt') ? 1 : 0;
            $grid[$option . 'immediately'] = 1;
            $grid[$option . 'open'] = 1;
            $grid[$option . 'closed'] = 1;
        }
        return $grid;
    }

    /**
     * Add banked questions to a quiz, resolving each (bank-entry id, version) to
     * the question id the quiz API expects.
     *
     * @param \stdClass $quiz The created quiz module info (carries ->instance).
     * @param array<int, array{0:int,1:int}> $qrefs The [qbeid, version] pairs.
     * @return void
     */
    private function add_quiz_questions(\stdClass $quiz, array $qrefs): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quizrecord = $DB->get_record('quiz', ['id' => $quiz->instance], '*', MUST_EXIST);
        $quizrecord->cmid = $quiz->coursemodule;
        foreach ($qrefs as [$qbeid, $version]) {
            $questionid = $DB->get_field(
                'question_versions',
                'questionid',
                ['questionbankentryid' => $qbeid, 'version' => $version],
                MUST_EXIST
            );
            quiz_add_quiz_question((int) $questionid, $quizrecord);
        }
    }

    /**
     * Bank questions via quizgenpro's exporter into the course's default question
     * bank, and return the pinned (bank-entry id, version) pairs.
     *
     * @param \stdClass[] $questions Exporter-ready question objects.
     * @param \stdClass $course The course.
     * @return array<int, array{0:int,1:int}> The [qbeid, version] pairs.
     */
    private function bank_questions(array $questions, \stdClass $course): array {
        global $DB;
        $bankcontext = $this->course_question_bank_context($course);
        $export = (new \local_quizgenpro\exporter())->export_to_question_bank(
            json_encode($questions),
            (int) $course->id,
            (int) $bankcontext->id
        );

        $rows = $DB->get_records_sql(
            'SELECT qbe.id AS qbeid, MAX(qv.version) AS version
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              WHERE qbe.questioncategoryid = :catid
           GROUP BY qbe.id
           ORDER BY qbe.id',
            ['catid' => $export['catid']]
        );

        $refs = [];
        foreach ($rows as $row) {
            $refs[] = [(int) $row->qbeid, (int) $row->version];
        }
        return $refs;
    }

    /**
     * The context of the course's default open question bank, created on first use.
     *
     * @param \stdClass $course The course.
     * @return \context
     */
    private function course_question_bank_context(\stdClass $course): \context {
        if ($this->coursebankcontext !== null) {
            return $this->coursebankcontext;
        }
        $cm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
        $this->coursebankcontext = \context_module::instance($cm->id);
        return $this->coursebankcontext;
    }

    /**
     * Whether filter_knowledgecheck is active in the given context (so an
     * embedded token will render inline).
     *
     * @param \context $context The context.
     * @return bool
     */
    private function filter_enabled(\context $context): bool {
        return array_key_exists('knowledgecheck', filter_get_active_in_context($context));
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
        // Leave no orphan: drop any half-built hidden course.
        $this->cleanup_partial_course($job);
        $this->set_status($job, job_manager::STATUS_FAILED);
        $this->log($job, null, null, null, null, null, null, null, $reason, audit_log::FAILURE);
        mtrace("local_coursegen: materialize job {$job->id} failed: {$reason}");
    }

    /**
     * Refuse the rebuild WITHOUT any cleanup, leaving the existing course,
     * program and certification intact (D18). The previously-built course is live
     * and serving the allocated cohort, so the job is genuinely COMPLETE, not
     * failed — the refusal is surfaced through the audit log and the caller's
     * false return, not a misleading FAILED status. Use only before anything
     * destructive runs.
     *
     * @param \stdClass $job The job.
     * @param string $reason Non-sensitive, actionable reason.
     * @return void
     */
    private function refuse(\stdClass $job, string $reason): void {
        $this->set_status($job, job_manager::STATUS_COMPLETE);
        // Mark with a distinct stage (no AI tokens, so not via log()/spend accrual)
        // so the UI can tell a refusal apart from benign in-build skip failures (D20).
        audit_log::record(
            (int) $job->id,
            (int) $job->userid,
            job_manager::STAGE_REBUILD_REFUSED,
            audit_log::FAILURE,
            $reason
        );
        mtrace("local_coursegen: materialize job {$job->id} refused: {$reason}");
    }

    /**
     * The course's own live-learner-state clause, or null if none (D20). A
     * freshly-built course has zero enrolments and zero completion, so any of
     * these is a genuine addition that delete_course would destroy:
     *  - any enrolled learner (manual, self, cohort, …);
     *  - real completion progress (a finished activity, or course completion).
     *
     * Public+static so both the re-materialize refusal (D20) and the operator's
     * opt-in course delete (D31) share one definition of "has learner state".
     *
     * @param int|null $courseid The course id (null/0/missing course -> null).
     * @return string|null The clause, or null if the course holds no learner state.
     */
    public static function course_learner_state_reason(?int $courseid): ?string {
        global $DB, $CFG;
        // A public entry point: callers (e.g. the operator delete confirm) may not
        // have completionlib loaded, so COMPLETION_INCOMPLETE could be undefined.
        require_once($CFG->libdir . '/completionlib.php');
        if (empty($courseid) || !$DB->record_exists('course', ['id' => $courseid])) {
            return null;
        }
        $parts = [];

        $enrolled = (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT ue.userid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid",
            ['courseid' => $courseid]
        );
        if ($enrolled > 0) {
            $parts[] = "the course has {$enrolled} enrolled learner(s)";
        }

        $progress = (int) $DB->get_field_sql(
            "SELECT COUNT(cmc.id)
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.completionstate <> :incomplete",
            ['courseid' => $courseid, 'incomplete' => COMPLETION_INCOMPLETE]
        );
        $progress += $DB->count_records_select(
            'course_completions',
            'course = :courseid AND timecompleted IS NOT NULL',
            ['courseid' => $courseid]
        );
        if ($progress > 0) {
            $parts[] = "{$progress} learner completion record(s)";
        }

        return $parts ? implode('; ', $parts) : null;
    }

    /**
     * Tear down a generated course: the shared MECHANISM (D31) for removing a
     * course this plugin created, used by the internal rebuild cleanup, the
     * operator's opt-in course deletion (job_manager), and the privacy erase path.
     *
     * Mechanism only — NO capability check, NO confirm, NO learner-state gate
     * (those belong to the operator path, not here). Deletes the course via
     * delete_course(); the quizgenpro question categories banked for the course
     * live in a question-bank MODULE context inside the course, so they (and their
     * entries, versions, and questions) are torn down by the course-context cascade
     * — there is no separate category to sweep, and an idnumber sweep would risk
     * other courses' categories (D31). A no-op if the course no longer exists.
     *
     * @param int $courseid The generated course id.
     * @return void
     */
    public static function teardown_generated_course(int $courseid): void {
        global $DB, $CFG;
        if (empty($courseid) || !$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        delete_course($courseid, false);
    }

    /**
     * Delete a previously-created (partial) course for the job, if any, and
     * clear the recorded courseid. Safe because generated courses are always
     * hidden and never learner-visible. Routes through the shared teardown
     * mechanism (D31).
     *
     * @param \stdClass $job The job (courseid cleared in place).
     * @return void
     */
    private function cleanup_partial_course(\stdClass $job): void {
        global $DB;
        if (empty($job->courseid)) {
            return;
        }
        self::teardown_generated_course((int) $job->courseid);
        $job->courseid = null;
        $DB->set_field('coursegen_job', 'courseid', null, ['id' => $job->id]);
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
     * @param string $outcome audit_log::SUCCESS or audit_log::FAILURE (required).
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
        string $outcome
    ): void {
        global $DB;
        audit_log::record((int) $job->id, (int) $job->userid, self::STAGE, $outcome, $detail, [
            'tier' => $tier,
            'actionname' => $actionname,
            'provider' => $provider,
            'model' => $model,
            'tokensin' => $tokensin,
            'tokensout' => $tokensout,
            'imagecount' => $imagecount,
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
