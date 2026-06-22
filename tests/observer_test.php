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
 * Tests for the course_deleted observer (D31).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Deleting a course flags every job that referenced it, without archiving them.
 */
#[CoversClass(\local_coursegen\observer::class)]
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a course flags ALL jobs that pointed at it (rebuilds can produce
     * several), setting timecoursedeleted and nulling courseid, while leaving the
     * jobs un-archived and with their real status and logs intact.
     *
     * @return void
     */
    public function test_course_deleted_flags_all_matching_jobs(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $contextid = \context_coursecat::instance($category->id)->id;
        $course = $this->getDataGenerator()->create_course();

        // Two jobs share the same courseid (a rebuild reused the link); one other
        // job points elsewhere and must be untouched.
        $job1 = $this->job($contextid, (int) $course->id);
        $job2 = $this->job($contextid, (int) $course->id);
        $other = $this->job($contextid, 999999);
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job1, 'stage' => 'materialize', 'outcome' => 'success',
            'tokensin' => 100, 'timecreated' => time(),
        ]);

        delete_course($course->id, false);

        foreach ([$job1, $job2] as $jobid) {
            $rec = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
            $this->assertNull($rec->courseid, 'courseid should be nulled');
            $this->assertNotNull($rec->timecoursedeleted, 'timecoursedeleted should be set');
            $this->assertNull($rec->timearchived, 'the job must NOT be archived — only flagged');
            $this->assertSame('complete', $rec->status, 'the real status is preserved');
        }
        // The job's audit/spend log survives.
        $this->assertEquals(1, $DB->count_records('coursegen_log', ['jobid' => $job1]));
        // The unrelated job is untouched.
        $otherrec = $DB->get_record('coursegen_job', ['id' => $other], '*', MUST_EXIST);
        $this->assertEquals(999999, (int) $otherrec->courseid);
        $this->assertNull($otherrec->timecoursedeleted);
    }

    /**
     * Insert a complete, active job with the given courseid.
     *
     * @param int $contextid The category context id.
     * @param int $courseid The course id.
     * @return int The job id.
     */
    private function job(int $contextid, int $courseid): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $contextid,
            'courseid' => $courseid,
            'mode' => 'outlinefirst',
            'status' => 'complete',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
    }
}
