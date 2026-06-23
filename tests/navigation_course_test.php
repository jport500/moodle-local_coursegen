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
 * Tests for the course "More" navigation item (Surface 2, D35).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/coursegen/lib.php');

/**
 * The item appears only on builder-generated courses, only for users who can
 * build in the job's CATEGORY context, targets the latest job, and survives
 * archiving.
 */
#[CoversFunction('local_coursegen_extend_navigation_course')]
final class navigation_course_test extends \advanced_testcase {
    /**
     * Build the course node, run the callback, and return the added item node or
     * false (the item key is 'local_coursegen_view').
     *
     * @param \stdClass $course The course.
     * @return \navigation_node|false
     */
    private function item_for(\stdClass $course): \navigation_node|false {
        $node = \navigation_node::create('Course admin', null, \navigation_node::TYPE_COURSE, null, 'courseadmin');
        local_coursegen_extend_navigation_course($node, $course, \context_course::instance($course->id));
        return $node->find('local_coursegen_view', \navigation_node::TYPE_SETTING);
    }

    /**
     * Insert a job that "generated" the given course, in the given category context.
     *
     * @param int $courseid The course id.
     * @param int $contextid The category context id.
     * @param array $extra Extra job fields (e.g. timearchived).
     * @return int The job id.
     */
    private function job(int $courseid, int $contextid, array $extra = []): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('coursegen_job', (object) array_merge([
            'userid' => 2, 'contextid' => $contextid, 'courseid' => $courseid, 'mode' => 'automatic',
            'status' => 'complete', 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 2,
        ], $extra));
    }

    /**
     * Item appears for an admin (can build anywhere) on a generated course, and
     * deep-links to the generating job.
     *
     * @return void
     */
    public function test_appears_on_generated_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $jobid = $this->job((int) $course->id, \context_coursecat::instance($cat->id)->id);

        $item = $this->item_for($course);
        $this->assertNotFalse($item);
        $this->assertStringContainsString('jobid=' . $jobid, $item->action()->out(false));
    }

    /**
     * No job for the course → hidden (not builder-generated).
     *
     * @return void
     */
    public function test_hidden_on_non_generated_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse($this->item_for($course));
    }

    /**
     * Wrong-context guard: a user who can EDIT the course (editingteacher, which
     * carries the builder cap in the COURSE context) but has no role in the
     * CATEGORY must NOT see the item — the cap is checked in the category context.
     *
     * @return void
     */
    public function test_hidden_when_no_builder_access_in_category(): void {
        $this->resetAfterTest();
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $this->job((int) $course->id, \context_coursecat::instance($cat->id)->id);

        // Editingteacher in the COURSE only (no role in the category).
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        // They have the builder cap in the course context, but not the category.
        $this->assertTrue(has_capability('local/coursegen:generate', \context_course::instance($course->id)));
        $this->assertFalse(has_capability('local/coursegen:generate', \context_coursecat::instance($cat->id)));
        // So the item is hidden (checked in the category context, not the course).
        $this->assertFalse($this->item_for($course));
    }

    /**
     * When several jobs share the courseid (rebuilds), the item targets the most
     * recent job by id.
     *
     * @return void
     */
    public function test_targets_latest_job(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $ctxid = \context_coursecat::instance($cat->id)->id;
        $this->job((int) $course->id, $ctxid);
        $latest = $this->job((int) $course->id, $ctxid);

        $item = $this->item_for($course);
        $this->assertNotFalse($item);
        $this->assertStringContainsString('jobid=' . $latest, $item->action()->out(false));
    }

    /**
     * An archived job still shows the item (its record is more relevant archived,
     * not less; view.php renders the archived state).
     *
     * @return void
     */
    public function test_appears_for_archived_job(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $cat = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $cat->id]);
        $this->job((int) $course->id, \context_coursecat::instance($cat->id)->id, ['timearchived' => time()]);

        $this->assertNotFalse($this->item_for($course));
    }
}
