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
 * Event observers for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

/**
 * Reacts to Moodle events that affect generation jobs.
 */
class observer {
    /**
     * Flag jobs whose generated course was deleted (D31). Fires for ANY course
     * deletion — the plugin's own teardown, the operator's opt-in delete, or a
     * deletion via Moodle's course management. A courseid may match more than one
     * job (rebuilds reuse the link), so every matching job is flagged.
     *
     * The job is flagged, NOT archived: the operator should still see it and its
     * recorded cost after the course is gone. courseid is nulled so the link no
     * longer dangles. A successful re-materialize clears this flag as its last
     * step (see materializer::materialize), so a rebuild is not left mis-flagged.
     *
     * @param \core\event\course_deleted $event The course-deleted event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $courseid = (int) $event->objectid;
        $jobs = $DB->get_records('coursegen_job', ['courseid' => $courseid], '', 'id');
        if (!$jobs) {
            return;
        }
        $now = time();
        foreach ($jobs as $job) {
            $DB->update_record('coursegen_job', (object) [
                'id' => $job->id,
                'courseid' => null,
                'timecoursedeleted' => $now,
                'timemodified' => $now,
            ]);
        }
    }
}
