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
 * Tests for materialization (offline, stubbed text + image clients).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\stub_image_client;
use local_coursegen\local\ai\stub_quiz_client;
use local_coursegen\local\ai\stub_text_client;
use local_coursegen\local\ai\text_result;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\job_manager;
use local_coursegen\local\materializer;
use local_coursegen\local\review_gate;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_text_client.php');
require_once(__DIR__ . '/fixtures/stub_image_client.php');
require_once(__DIR__ . '/fixtures/stub_quiz_client.php');

/**
 * Tests materializer.
 */
#[CoversClass(\local_coursegen\local\materializer::class)]
final class materializer_test extends \advanced_testcase {
    /**
     * A hidden pathway course is built with a label per section, an embedded
     * image where flagged, spend accrued, and the job advanced to complete.
     *
     * @return void
     */
    public function test_materializes_hidden_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job();

        $image = new stub_image_client(true);
        $ok = (new materializer($this->text(), $image, new stub_quiz_client(true)))->materialize($job);
        $this->assertTrue($ok);

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertNotEmpty($job->courseid);

        $course = $DB->get_record('course', ['id' => $job->courseid], '*', MUST_EXIST);
        $this->assertEquals(0, $course->visible);
        $this->assertSame('pathway', $course->format);
        $this->assertEquals(1, $course->enablecompletion);

        // A label per content section plus the intro and wrap-up bookends (D25).
        $this->assertEquals(4, $DB->count_records('label', ['course' => $course->id]));
        // One flagged section image plus the course thumbnail (D25).
        $this->assertEquals(2, $image->call_count());
        $this->assertTrue($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':img'),
            ['c' => $course->id, 'img' => '%<img%']
        ));

        // The embedded image is stored in a label's intro file area.
        $hasfile = false;
        foreach (get_fast_modinfo($course->id)->get_instances_of('label') as $cm) {
            if (get_file_storage()->get_area_files($cm->context->id, 'mod_label', 'intro', 0, 'id', false)) {
                $hasfile = true;
            }
        }
        $this->assertTrue($hasfile);

        // Spend accrued from the §10.2 token logs, and an image call recorded.
        $this->assertGreaterThan(0, (int) $job->actualspend);
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'materialize', 'actionname' => 'generate_image', 'imagecount' => 1]
        ));
    }

    /**
     * An estimate over the remaining spend cap hard-stops before any course.
     *
     * @return void
     */
    public function test_over_spend_cap_hard_stops(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('cap_period_spend', 1, 'local_coursegen');
        $job = $this->approved_job();

        ob_start();
        $ok = (new materializer($this->text(), new stub_image_client(true), new stub_quiz_client(true)))
            ->materialize($job);
        ob_end_clean();

        $this->assertFalse($ok);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
        $this->assertEmpty($job->courseid);
        $this->assertEquals(0, $DB->count_records('course', ['shortname' => 'coursegen-' . $job->id]));
    }

    /**
     * With no image budget, the course still builds but without images.
     *
     * @return void
     */
    public function test_image_subcap_skips_images(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Image budget of 1, already fully consumed this period.
        set_config('cap_image_count', 1, 'local_coursegen');
        $job = $this->approved_job();
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id, 'stage' => 'materialize', 'imagecount' => 1,
            'outcome' => 'success', 'timecreated' => time(),
        ]);

        $image = new stub_image_client(true);
        $this->assertTrue((new materializer($this->text(), $image, new stub_quiz_client(true)))->materialize($job));

        $this->assertEquals(0, $image->call_count());
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertFalse($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':img'),
            ['c' => $job->courseid, 'img' => '%<img%']
        ));
    }

    /**
     * A text-generation failure is tolerated and logged; the course completes.
     *
     * @return void
     */
    public function test_text_failure_is_tolerated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job();

        $failtext = new stub_text_client([new text_result(success: false, provider: 'p', error: 'boom')]);
        $this->assertTrue((new materializer($failtext, new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'materialize', 'outcome' => 'failure']
        ));
    }

    /**
     * With the filter enabled, an assessed section gets a STEALTH knowledge
     * check with the banked questions pinned, automatic completion, and its
     * {knowledgecheck} token embedded in the section label.
     *
     * @return void
     */
    public function test_assessed_section_gets_stealth_knowledgecheck(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        filter_set_global_state('knowledgecheck', TEXTFILTER_ON);
        $job = $this->approved_job_with([
            ['title' => 'Assessed', 'summary' => 's', 'objectives' => ['o'],
             'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 3]],
        ]);

        $ok = (new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job);
        $this->assertTrue($ok);

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $kcs = $DB->get_records('knowledgecheck', ['course' => $job->courseid]);
        $this->assertCount(1, $kcs);
        $kc = reset($kcs);
        $this->assertEquals(3, $DB->count_records('knowledgecheck_questions', ['knowledgecheckid' => $kc->id]));

        $cm = get_coursemodule_from_instance('knowledgecheck', $kc->id);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, (int) $cm->completion);
        $this->assertEquals(0, (int) $cm->visibleoncoursepage); // Stealth.
        $this->assertEquals(1, (int) $kc->completionsubmit);

        // The token is embedded in the section label.
        $this->assertTrue($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':tok'),
            ['c' => $job->courseid, 'tok' => '%{knowledgecheck id=' . $kc->uuid . '}%']
        ));
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'tier' => 'assessment', 'outcome' => 'success']
        ));

        // One tracked activity per section (D21): the check carries completion, so
        // the reading label is untracked.
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->label_completion($job->courseid));
    }

    /**
     * With the filter DISABLED, the check is created non-stealth (shown on the
     * course page) with no token, and the course still completes.
     *
     * @return void
     */
    public function test_filter_disabled_non_stealth_fallback(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        filter_set_global_state('knowledgecheck', TEXTFILTER_DISABLED);
        $job = $this->approved_job_with([
            ['title' => 'Assessed', 'summary' => 's', 'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 2]],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $kc = $DB->get_record('knowledgecheck', ['course' => $job->courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('knowledgecheck', $kc->id);
        $this->assertEquals(1, (int) $cm->visibleoncoursepage); // Non-stealth fallback.
        $this->assertFalse($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':tok'),
            ['c' => $job->courseid, 'tok' => '%{knowledgecheck%']
        ));
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        // The check was still built, so it (not the label) carries completion.
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->label_completion($job->courseid));
    }

    /**
     * A type=none section gets no knowledge check, and the quiz client is not called.
     *
     * @return void
     */
    public function test_none_section_no_check(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Reading only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);

        $quizclient = new stub_quiz_client(true);
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), $quizclient))
            ->materialize($job));

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertEquals(0, $DB->count_records('knowledgecheck', ['course' => $job->courseid]));
        $this->assertEquals(0, $quizclient->call_count());
        // Reading-only section: the label is the one completion signal (D21).
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->label_completion($job->courseid));
    }

    /**
     * No usable questions skips the check but still completes the course.
     *
     * @return void
     */
    public function test_check_failure_skips_and_builds(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('knowledgecheck', TEXTFILTER_ON);
        $job = $this->approved_job_with([
            ['title' => 'Assessed', 'summary' => 's', 'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 2]],
        ]);

        // Quiz client returns no questions.
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(false)))
            ->materialize($job));

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertEquals(0, $DB->count_records('knowledgecheck', ['course' => $job->courseid]));
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'tier' => 'assessment', 'outcome' => 'failure']
        ));
        // No check was built, so the reading label remains the section's one
        // completion signal — the section is never left uncompletable (D21).
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->label_completion($job->courseid));
    }

    /**
     * The built course requires every tracked activity (one criterion per section),
     * aggregated ALL — so course completion can fire and feed the cert chain (D22).
     *
     * @return void
     */
    public function test_course_completion_criteria_configured(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        filter_set_global_state('knowledgecheck', TEXTFILTER_ON);
        $job = $this->approved_job_with([
            ['title' => 'Assessed', 'summary' => 's', 'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 2]],
            ['title' => 'Reading only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        // One activity criterion per tracked CM (the KC and the reading label), and
        // every criterion points at a real completion-tracked module in the course.
        $criteria = $DB->get_records('course_completion_criteria', ['course' => $job->courseid]);
        $this->assertCount(2, $criteria);
        // The course has 4 labels (intro + 2 content + wrap-up) but only the 2
        // content tracked activities are criteria — the bookends contribute nothing.
        $this->assertEquals(4, $DB->count_records('label', ['course' => $job->courseid]));
        $this->assertEquals(2, $DB->count_records_select(
            'course_modules',
            'course = :course AND completion <> :none AND deletioninprogress = 0',
            ['course' => $job->courseid, 'none' => COMPLETION_TRACKING_NONE]
        ));
        foreach ($criteria as $c) {
            $this->assertTrue($DB->record_exists_select(
                'course_modules',
                'id = :id AND course = :course AND completion <> :none',
                ['id' => $c->moduleinstance, 'course' => $job->courseid, 'none' => COMPLETION_TRACKING_NONE]
            ));
        }
        // Overall aggregation is ALL: every tracked activity must be completed.
        $this->assertEquals(COMPLETION_AGGREGATION_ALL, (int) $DB->get_field(
            'course_completion_aggr_methd',
            'method',
            ['course' => $job->courseid, 'criteriatype' => null]
        ));
    }

    /**
     * The configured criteria actually DRIVE course completion: completing every
     * tracked activity sets course_completions.timecompleted (D22).
     *
     * @return void
     */
    public function test_criteria_drive_course_completion(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        $job = $this->approved_job_with([
            ['title' => 'Reading only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $job->courseid], '*', MUST_EXIST);
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Complete the one tracked activity (the manual reading label).
        $cmid = (int) $DB->get_field_sql(
            "SELECT cm.id FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'label'
              WHERE cm.course = :course AND cm.completion <> :none",
            ['course' => $course->id, 'none' => COMPLETION_TRACKING_NONE]
        );
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $learner->id);

        // Course completion is aggregated by the scheduled task.
        ob_start();
        (new \core\task\completion_regular_task())->execute();
        ob_end_clean();

        $ccompletion = new \completion_completion(['course' => $course->id, 'userid' => $learner->id]);
        $this->assertTrue(
            $ccompletion->is_complete(),
            'Configured criteria did not drive course_completions.'
        );
    }

    /**
     * A quiz section builds a separate, GRADED mod_quiz with the banked questions,
     * a grade-to-pass, pass-based automatic completion, and an untracked reading
     * label (the quiz is the section's tracked activity) — D23.
     *
     * @return void
     */
    public function test_quiz_section_builds_graded_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        $job = $this->approved_job_with([
            ['title' => 'Exam', 'summary' => 's', 'assessment' => ['type' => 'quiz', 'questioncount' => 2]],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $quiz = $DB->get_record('quiz', ['course' => $job->courseid], '*', MUST_EXIST);
        $this->assertEquals(2, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]), 'Banked questions not on the quiz.');
        $this->assertGreaterThan(0, (float) $quiz->sumgrades, 'Quiz sumgrades was not recomputed.');

        $cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, (int) $cm->completion);
        $this->assertEquals(1, (int) $cm->completionpassgrade);
        // Use-grade-for-completion is stored as a non-null grade-item number.
        $this->assertNotNull($cm->completiongradeitemnumber);
        $this->assertEquals(0, (int) $cm->completiongradeitemnumber);
        $this->assertEquals(1, (int) $cm->visibleoncoursepage); // A graded quiz is a visible click-through.

        // The grade item carries the pass grade that completion gates on.
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'courseid' => $job->courseid, 'itemnumber' => 0,
        ]);
        $this->assertEqualsWithDelta(50.0, (float) $item->gradepass, 0.001);

        // The reading label is untracked; the quiz is the section's signal.
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->label_completion($job->courseid));
    }

    /**
     * A quiz whose questions can't be generated is skipped, and the reading label
     * reverts to manual completion so the section is never uncompletable (D23/D14).
     *
     * @return void
     */
    public function test_quiz_failure_skips_and_label_manual(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        $job = $this->approved_job_with([
            ['title' => 'Exam', 'summary' => 's', 'assessment' => ['type' => 'quiz', 'questioncount' => 2]],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(false)))
            ->materialize($job));
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $this->assertEquals(0, $DB->count_records('quiz', ['course' => $job->courseid]));
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->label_completion($job->courseid));
    }

    /**
     * The function test: PASSING the quiz completes the course; a graded-but-FAILED
     * attempt does not (the summative gate, D23). Driven through the grade item the
     * quiz's completion reads, so it exercises the real gradepass/completionpassgrade
     * wiring and P15's course criteria.
     *
     * @return void
     */
    public function test_quiz_pass_completes_course_fail_does_not(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        $job = $this->approved_job_with([
            ['title' => 'Exam', 'summary' => 's', 'assessment' => ['type' => 'quiz', 'questioncount' => 2]],
        ]);
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $job->courseid], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['course' => $course->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm((int) get_coursemodule_from_instance('quiz', $quiz->id)->id);
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'courseid' => $course->id, 'itemnumber' => 0,
        ]);
        $completion = new \completion_info($course);

        // Passer: grade above the 50 pass mark -> COMPLETE_PASS -> course completes.
        $passer = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $item->update_final_grade($passer->id, 80.0);
        $completion->update_state($cm, COMPLETION_UNKNOWN, $passer->id);
        $this->assertEquals(
            COMPLETION_COMPLETE_PASS,
            (int) $completion->get_data($cm, false, $passer->id)->completionstate
        );

        // Failer: graded below the pass mark -> COMPLETE_FAIL (done, but failed).
        $failer = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $item->update_final_grade($failer->id, 20.0);
        $completion->update_state($cm, COMPLETION_UNKNOWN, $failer->id);
        $this->assertEquals(
            COMPLETION_COMPLETE_FAIL,
            (int) $completion->get_data($cm, false, $failer->id)->completionstate
        );

        // Aggregate course completion.
        ob_start();
        (new \core\task\completion_regular_task())->execute();
        ob_end_clean();

        $this->assertTrue(
            (new \completion_completion(['course' => $course->id, 'userid' => $passer->id]))->is_complete(),
            'Passing the quiz did not complete the course.'
        );
        $this->assertFalse(
            (new \completion_completion(['course' => $course->id, 'userid' => $failer->id]))->is_complete(),
            'A failed quiz attempt wrongly completed the course.'
        );
    }

    /**
     * The build is bracketed by an untracked intro (first in the flow, with the
     * overview) and an untracked wrap-up (last) — both labels NONE (D25).
     *
     * @return void
     */
    public function test_intro_and_final_bookends(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Widgets', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $courseid = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        // Numbered sections in order: Introduction, the content section, Wrap-up.
        $sections = array_values($DB->get_records_select(
            'course_sections',
            'course = :course AND section > 0',
            ['course' => $courseid],
            'section ASC'
        ));
        $this->assertCount(3, $sections);
        $this->assertSame(get_string('introsection_name', 'local_coursegen'), $sections[0]->name);
        $this->assertSame('Widgets', $sections[1]->name);
        $this->assertSame(get_string('finalsection_name', 'local_coursegen'), $sections[2]->name);

        // Both bookend labels are untracked; the intro carries the overview list.
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->section_label_completion($courseid, (int) $sections[0]->id));
        $this->assertEquals(COMPLETION_TRACKING_NONE, $this->section_label_completion($courseid, (int) $sections[2]->id));
        $this->assertTrue($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':covers'),
            ['c' => $courseid, 'covers' => '%' . get_string('introsection_covers', 'local_coursegen') . '%']
        ));
    }

    /**
     * Despite the front-shift from the intro section, an assessed content section
     * builds its activity in the correct (offset) section (D25).
     *
     * @return void
     */
    public function test_assessment_lands_in_offset_section(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        filter_set_global_state('knowledgecheck', TEXTFILTER_ON);
        $job = $this->approved_job_with([
            ['title' => 'Reading', 'summary' => 's', 'assessment' => ['type' => 'none']],
            ['title' => 'Assessed', 'summary' => 's', 'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 2]],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $courseid = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        // Sections: 1 intro, 2 Reading, 3 Assessed, 4 wrap-up. The KC is in 3.
        $kc = $DB->get_record('knowledgecheck', ['course' => $courseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('knowledgecheck', $kc->id);
        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
        $this->assertSame(3, (int) $section->section, 'The knowledge check is in the wrong (mis-offset) section.');
        $this->assertSame('Assessed', $section->name);
    }

    /**
     * A course thumbnail is set as the course image when images are opted in, and
     * counts as one image against the sub-cap (D25).
     *
     * @return void
     */
    public function test_thumbnail_set_when_images_on(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Widgets', 'summary' => 's', 'image' => ['generate' => true],
                'assessment' => ['type' => 'none']],
        ]);

        $image = new stub_image_client(true);
        $this->assertTrue((new materializer($this->text(), $image, new stub_quiz_client(true)))->materialize($job));
        $courseid = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        $files = get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id,
            'course',
            'overviewfiles',
            0,
            'id',
            false
        );
        $this->assertCount(1, $files, 'The course thumbnail was not set as the course image.');
        // One section image plus the thumbnail.
        $this->assertEquals(2, $image->call_count());
    }

    /**
     * No thumbnail (and no failure) when images are off — i.e. no section opted in.
     *
     * @return void
     */
    public function test_thumbnail_skipped_when_images_off(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Widgets', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);

        $image = new stub_image_client(true);
        $this->assertTrue((new materializer($this->text(), $image, new stub_quiz_client(true)))->materialize($job));
        $courseid = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        $this->assertEquals(0, $image->call_count(), 'An image was generated with no opt-in.');
        $this->assertCount(0, get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id,
            'course',
            'overviewfiles',
            0,
            'id',
            false
        ));
        $this->assertSame(
            job_manager::STATUS_COMPLETE,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * When the image sub-cap is exhausted by the section image, the thumbnail is
     * skipped (not failed) and no course image is set (D25).
     *
     * @return void
     */
    public function test_thumbnail_skipped_when_subcap_exhausted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('cap_image_count', 1, 'local_coursegen'); // Only one image this period.
        $job = $this->approved_job_with([
            ['title' => 'Widgets', 'summary' => 's', 'image' => ['generate' => true],
                'assessment' => ['type' => 'none']],
        ]);

        $image = new stub_image_client(true);
        $this->assertTrue((new materializer($this->text(), $image, new stub_quiz_client(true)))->materialize($job));
        $courseid = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        // The one image went to the section; the thumbnail is skipped, not failed.
        $this->assertEquals(1, $image->call_count());
        $this->assertCount(0, get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id,
            'course',
            'overviewfiles',
            0,
            'id',
            false
        ));
    }

    /**
     * The completion tracking of the (single) label in a given course section.
     *
     * @param int $courseid The course id.
     * @param int $sectionid The course_sections.id.
     * @return int A COMPLETION_TRACKING_* value.
     */
    private function section_label_completion(int $courseid, int $sectionid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            "SELECT cm.completion
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'label'
              WHERE cm.course = :courseid AND cm.section = :sectionid",
            ['courseid' => $courseid, 'sectionid' => $sectionid]
        );
    }

    /**
     * The reading label's completion tracking for a single content-section course,
     * excluding the untracked intro/wrap-up bookends (D25).
     *
     * @param int $courseid The course id.
     * @return int A COMPLETION_TRACKING_* value.
     */
    private function label_completion(int $courseid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            "SELECT cm.completion
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'label'
               JOIN {course_sections} s ON s.id = cm.section
              WHERE cm.course = :courseid AND s.name NOT IN (:intro, :final)",
            [
                'courseid' => $courseid,
                'intro' => get_string('introsection_name', 'local_coursegen'),
                'final' => get_string('finalsection_name', 'local_coursegen'),
            ]
        );
    }

    /**
     * A retry of a job stuck at "materializing" deletes the prior partial
     * course and rebuilds — never a second course, never stranded.
     *
     * @return void
     */
    public function test_reentrant_rebuild_no_duplicate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $firstcourse = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);
        $this->assertNotEmpty($firstcourse);

        // Simulate a prior attempt that died mid-run (status left at materializing).
        $DB->set_field('coursegen_job', 'status', job_manager::STATUS_MATERIALIZING, ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $secondcourse = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        $this->assertNotEquals($firstcourse, $secondcourse);
        $this->assertFalse($DB->record_exists('course', ['id' => $firstcourse]));
        $this->assertEquals(1, $DB->count_records('course', ['shortname' => 'coursegen-' . $job->id]));
        $this->assertSame(
            job_manager::STATUS_COMPLETE,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * Regression guard: a freshly built course with no learners rebuilds cleanly
     * — the course-state guard must NOT fire on the zero baseline (D20).
     *
     * @return void
     */
    public function test_clean_rebuild_not_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $firstcourse = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        // Nobody has touched the course, so a re-approve + rebuild must proceed.
        $DB->set_field('coursegen_job', 'status', job_manager::STATUS_APPROVED, ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));

        $secondcourse = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);
        $this->assertNotEquals($firstcourse, $secondcourse);
        $this->assertSame(
            job_manager::STATUS_COMPLETE,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * A learner enrolled directly (no wrap) blocks the rebuild; the course and the
     * enrolment are intact and the refusal audited. Unenrolling then lets the
     * rebuild proceed (D20).
     *
     * @return void
     */
    public function test_direct_enrolment_refuses_then_retry_after_unenrol(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $course = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        // A learner is directly enrolled (e.g. after an admin unhid the course).
        $learner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($learner->id, $course);
        $coursecontext = \context_course::instance($course);
        $this->assertTrue(is_enrolled($coursecontext, $learner));

        $DB->set_field('coursegen_job', 'status', job_manager::STATUS_APPROVED, ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        ob_start();
        $ok = (new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job);
        ob_end_clean();

        $this->assertFalse($ok, 'Re-materialize was not refused for a directly-enrolled learner.');
        $this->assertSame(
            job_manager::STATUS_COMPLETE,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
        $this->assertTrue($DB->record_exists('course', ['id' => $course]), 'The course was deleted.');
        $this->assertTrue(is_enrolled($coursecontext, $learner), 'The learner was unenrolled.');
        $this->assertTrue($DB->record_exists_select(
            'coursegen_log',
            'jobid = :jobid AND outcome = :outcome AND ' . $DB->sql_like('detail', ':detail'),
            ['jobid' => $job->id, 'outcome' => 'failure', 'detail' => '%enrolled learner%']
        ));

        // Retry after unenrolling: the rebuild now proceeds.
        $instance = $DB->get_record_sql(
            "SELECT e.* FROM {enrol} e WHERE e.courseid = :courseid AND e.enrol = :manual",
            ['courseid' => $course, 'manual' => 'manual']
        );
        enrol_get_plugin('manual')->unenrol_user($instance, $learner->id);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        review_gate::reopen_for_reedit($job, 2);
        review_gate::approve($job, 2);

        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $this->assertFalse($DB->record_exists('course', ['id' => $course]), 'The old course was not replaced.');
        $this->assertSame(
            job_manager::STATUS_COMPLETE,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * A real activity-completion record (no enrolment, no wrap) blocks the rebuild
     * — learner progress must not be silently destroyed (D20).
     *
     * @return void
     */
    public function test_completion_record_refuses_rematerialize(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job_with([
            ['title' => 'Only', 'summary' => 's', 'assessment' => ['type' => 'none']],
        ]);
        $this->assertTrue((new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job));
        $course = (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $job->id]);

        // A learner completed an activity in the course.
        $learner = $this->getDataGenerator()->create_user();
        $cmid = (int) $DB->get_field_sql(
            'SELECT MIN(id) FROM {course_modules} WHERE course = :course',
            ['course' => $course]
        );
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cmid,
            'userid' => $learner->id,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);

        $DB->set_field('coursegen_job', 'status', job_manager::STATUS_APPROVED, ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        ob_start();
        $ok = (new materializer($this->text(), new stub_image_client(false), new stub_quiz_client(true)))
            ->materialize($job);
        ob_end_clean();

        $this->assertFalse($ok, 'Re-materialize was not refused despite a completion record.');
        $this->assertTrue($DB->record_exists('course', ['id' => $course]));
        $this->assertTrue($DB->record_exists_select(
            'coursegen_log',
            'jobid = :jobid AND outcome = :outcome AND ' . $DB->sql_like('detail', ':detail'),
            ['jobid' => $job->id, 'outcome' => 'failure', 'detail' => '%completion record%']
        ));
    }

    /**
     * The audience level (D26 Fix 2) is threaded into the per-section reading
     * prompt as a prose pitch — beginner and advanced jobs ask for visibly
     * different prose from the drafting tier.
     *
     * @return void
     */
    public function test_audience_level_pitches_the_reading_prompt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $section = [[
            'title' => 'Topic', 'objectives' => ['Learn it'], 'summary' => 'About the topic.',
            'image' => ['generate' => false], 'assessment' => ['type' => 'none', 'questioncount' => 0],
        ]];

        // Beginner job.
        $job = $this->approved_job_with($section);
        $DB->set_field('coursegen_job', 'audiencelevel', 'beginner', ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $beginnerstub = $this->text();
        $this->assertTrue(
            (new materializer($beginnerstub, new stub_image_client(false), new stub_quiz_client(true)))->materialize($job)
        );
        $beginnerprompt = $beginnerstub->prompts()[0];
        $this->assertStringContainsString('complete beginner', $beginnerprompt);
        $this->assertStringContainsString('define every term', $beginnerprompt);
        $this->assertStringNotContainsString('working expertise', $beginnerprompt);

        // Advanced job, same section.
        $job2 = $this->approved_job_with($section);
        $DB->set_field('coursegen_job', 'audiencelevel', 'advanced', ['id' => $job2->id]);
        $job2 = $DB->get_record('coursegen_job', ['id' => $job2->id], '*', MUST_EXIST);
        $advancedstub = $this->text();
        $this->assertTrue(
            (new materializer($advancedstub, new stub_image_client(false), new stub_quiz_client(true)))->materialize($job2)
        );
        $advancedprompt = $advancedstub->prompts()[0];
        $this->assertStringContainsString('working expertise', $advancedprompt);
        $this->assertStringContainsString('tradeoffs', $advancedprompt);
        $this->assertStringNotContainsString('complete beginner', $advancedprompt);
    }

    /**
     * Insert an approved job with the given section specs.
     *
     * @param array[] $sections Section specs in blueprint::from_array shape.
     * @return \stdClass The job.
     */
    private function approved_job_with(array $sections): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'automatic',
            'status' => job_manager::STATUS_APPROVED,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $blueprint = blueprint::from_array(['title' => 'Course', 'description' => 'd', 'sections' => $sections]);
        blueprint_store::save_new_version($job, $blueprint, 2);
        return $job;
    }

    /**
     * Insert an approved job with a two-section blueprint (section 0 flagged
     * for an image).
     *
     * @return \stdClass The job.
     */
    private function approved_job(): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'automatic',
            'status' => job_manager::STATUS_APPROVED,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $blueprint = blueprint::from_array([
            'title' => 'Test Course',
            'description' => 'A description.',
            'sections' => [
                ['title' => 'Intro', 'objectives' => ['Understand'], 'summary' => 's1',
                 'image' => ['generate' => true, 'prompthint' => 'a diagram']],
                ['title' => 'Advanced', 'objectives' => ['Apply'], 'summary' => 's2',
                 'image' => ['generate' => false]],
            ],
        ]);
        blueprint_store::save_new_version($job, $blueprint, 2);
        return $job;
    }

    /**
     * A stub text client that returns reading HTML for every call.
     *
     * @return stub_text_client
     */
    private function text(): stub_text_client {
        return new stub_text_client([
            new text_result(
                success: true,
                content: '<p>Reading content for the section.</p>',
                model: 'stub-model',
                provider: 'stubprovider',
                prompttokens: 9,
                completiontokens: 40,
            ),
        ]);
    }
}
