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
 * Tests for the job archive lifecycle and management capability (D31).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\job_manager;
use local_coursegen\local\materializer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Archive hides a job from the default hub, unarchive restores it, the archived
 * job keeps its status and logs, and :manage / tenancy boundaries hold.
 */
#[CoversClass(\local_coursegen\local\job_manager::class)]
final class job_lifecycle_test extends \advanced_testcase {
    /**
     * Archiving removes a job from the default hub query but keeps it in the
     * include-archived view, preserving its real status and its log rows;
     * unarchiving restores it.
     *
     * @return void
     */
    public function test_archive_hides_unarchive_restores(): void {
        global $DB;
        $this->resetAfterTest();
        $contextid = \context_coursecat::instance($this->getDataGenerator()->create_category()->id)->id;
        $jobid = $this->job($contextid, null, 'complete');
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $jobid, 'stage' => 'blueprint', 'outcome' => 'success',
            'tokensin' => 50, 'timecreated' => time(),
        ]);

        // Active by default.
        $this->assertArrayHasKey($jobid, job_manager::jobs_in_context($contextid));

        job_manager::archive($jobid);

        // Hidden from the default (active) view; present in the archived view.
        $this->assertArrayNotHasKey($jobid, job_manager::jobs_in_context($contextid));
        $this->assertArrayHasKey($jobid, job_manager::jobs_in_context($contextid, true));
        // Status preserved; logs intact; the archive timestamp is set.
        $rec = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('complete', $rec->status);
        $this->assertNotNull($rec->timearchived);
        $this->assertEquals(1, $DB->count_records('coursegen_log', ['jobid' => $jobid]));

        job_manager::unarchive($jobid);
        $this->assertArrayHasKey($jobid, job_manager::jobs_in_context($contextid));
        $this->assertNull($DB->get_field('coursegen_job', 'timearchived', ['id' => $jobid]));
    }

    /**
     * The learner-state gate (shared with D20) fires for a course with activity
     * and is clear for a fresh course — this is what the operator delete path
     * warns on before allowing the override.
     *
     * @return void
     */
    public function test_learner_state_gate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        // Fresh course: no learner state.
        $this->assertNull(materializer::course_learner_state_reason((int) $course->id));

        // Enrol a learner: the gate now reports state.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $reason = materializer::course_learner_state_reason((int) $course->id);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('enrolled learner', $reason);

        // A missing course is clear (no gate).
        $this->assertNull(materializer::course_learner_state_reason(999999));
    }

    /**
     * Capability: :manage is required to manage; tenancy: the capability is
     * checked per category context, so a manager in one category cannot manage a
     * job in another.
     *
     * @return void
     */
    public function test_manage_capability_and_tenancy(): void {
        global $DB;
        $this->resetAfterTest();
        $cat1 = \context_coursecat::instance($this->getDataGenerator()->create_category()->id);
        $cat2 = \context_coursecat::instance($this->getDataGenerator()->create_category()->id);
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        // No role yet: cannot manage either context.
        $this->setUser($manager);
        $this->assertFalse(job_manager::can_manage($cat1, $manager));

        // Manager in cat1 only.
        role_assign($roleid, $manager->id, $cat1->id);
        $this->assertTrue(job_manager::can_manage($cat1, $manager));
        $this->assertFalse(job_manager::can_manage($cat2, $manager), 'tenancy: no rights in another category');
    }

    /**
     * Insert a job with the given context, course and status.
     *
     * @param int $contextid The category context id.
     * @param int|null $courseid The course id, or null.
     * @param string $status The pipeline status.
     * @return int The job id.
     */
    private function job(int $contextid, ?int $courseid, string $status): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $contextid,
            'courseid' => $courseid,
            'mode' => 'outlinefirst',
            'status' => $status,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
    }
}
