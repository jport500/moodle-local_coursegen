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
 * P6 gate: a knowledge-check submit must propagate completion.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Demonstrates the learner completion round-trip for an inline knowledge check:
 * submitting an attempt (the same api::submit_attempt the inline webservice
 * calls) marks the activity complete, which feeds format_pathway progress and,
 * via an activity completion criterion, course completion. The DOM render +
 * webservice POST is the one part left to the manual smoke; this exercises the
 * server path it drives.
 */
#[CoversNothing]
final class completion_walkthrough_test extends \advanced_testcase {
    /**
     * An inline knowledge-check submit completes the activity and feeds pathway progress.
     *
     * @return void
     */
    public function test_inline_submit_completes_activity_and_pathway(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $learner, $cm, $kcid, $answerid] = $this->scenario();

        $completion = new \completion_info($course);
        $this->assertEquals(
            COMPLETION_INCOMPLETE,
            (int) $completion->get_data($cm, false, $learner->id)->completionstate
        );

        // Submit as the learner via the inline path's core.
        $this->setUser($learner);
        \mod_knowledgecheck\api::submit_attempt($kcid, [1 => (string) $answerid]);

        $this->assertTrue($DB->record_exists(
            'knowledgecheck_attempts',
            ['knowledgecheckid' => $kcid, 'userid' => $learner->id, 'state' => 'finished']
        ));

        $completion = new \completion_info($course);
        $this->assertEquals(
            COMPLETION_COMPLETE,
            (int) $completion->get_data($cm, false, $learner->id)->completionstate,
            'Inline knowledge-check submit did not complete the activity.'
        );

        $this->assertEquals(
            100,
            \core_completion\progress::get_course_progress_percentage($course, $learner->id),
            'Completed knowledge check is not reflected in course progress.'
        );
    }

    /**
     * Activity completion from the submit drives course completion (via a
     * criterion + the regular completion aggregation task).
     *
     * @return void
     */
    public function test_submit_drives_course_completion(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        $this->resetAfterTest();
        [$course, $learner, $cm, $kcid, $answerid] = $this->scenario();

        // Configure completion via the SAME path production uses (D22), so this
        // asserts against the real wiring rather than criteria set up in-test.
        \local_coursegen\local\materializer::configure_course_completion((int) $course->id);

        $this->setUser($learner);
        \mod_knowledgecheck\api::submit_attempt($kcid, [1 => (string) $answerid]);

        // Course completion is aggregated by the scheduled task.
        ob_start();
        (new \core\task\completion_regular_task())->execute();
        ob_end_clean();

        $ccompletion = new \completion_completion(['course' => $course->id, 'userid' => $learner->id]);
        $this->assertTrue(
            $ccompletion->is_complete(),
            'Activity completion from the submit did not drive course completion.'
        );
    }

    /**
     * Build the scenario: a pathway course with completion on, an enrolled
     * learner, and a stealth knowledge check (completionsubmit) pinning one
     * true/false question. Returns [course, learner, cm, kcid, correctanswerid].
     *
     * @return array
     */
    private function scenario(): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $course = $this->getDataGenerator()->create_course(['format' => 'pathway', 'enablecompletion' => 1]);
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
        $bankcontext = \context_module::instance($bankcm->id);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $bankcontext->id]);
        $question = $qgen->create_question('truefalse', null, ['category' => $cat->id]);
        $qv = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);

        $moduleinfo = (object) [
            'modulename' => 'knowledgecheck',
            'module' => $DB->get_field('modules', 'id', ['name' => 'knowledgecheck'], MUST_EXIST),
            'course' => $course->id,
            'section' => 0,
            'visible' => 1,
            'visibleoncoursepage' => 0,
            'cmidnumber' => '',
            'name' => 'Gate check',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'completionview' => 0,
            'completionexpected' => 0,
            'completiongradeitemnumber' => null,
            'completionpassgrade' => 0,
        ];
        $kc = add_moduleinfo($moduleinfo, $course);
        \mod_knowledgecheck\local\questions::add(
            (int) $kc->instance,
            (int) $qv->questionbankentryid,
            (int) $qv->version
        );

        $cm = get_coursemodule_from_instance('knowledgecheck', $kc->instance, $course->id, false, MUST_EXIST);
        $answerid = (int) $DB->get_field_select(
            'question_answers',
            'id',
            'question = :q AND fraction > 0',
            ['q' => $question->id],
            IGNORE_MULTIPLE
        );

        return [$course, $learner, $cm, (int) $kc->instance, $answerid];
    }
}
