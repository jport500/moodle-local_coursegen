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
 * Versioned storage for blueprints.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * Persists and loads blueprints in coursegen_blueprint, keeping one current
 * version per job. Each save inserts a new row (version + 1, iscurrent = 1) and
 * demotes the previous current row, so the version that is approved/materialized
 * is retained (SPEC §3). The current row is the one P4 will read.
 */
class blueprint_store {
    /**
     * Save a blueprint as a new current version for the job.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param blueprint $blueprint The blueprint to store.
     * @param int $userid The user making the change.
     * @return int The new coursegen_blueprint row id.
     */
    public static function save_new_version(\stdClass $job, blueprint $blueprint, int $userid): int {
        global $DB;
        $now = time();
        $DB->set_field('coursegen_blueprint', 'iscurrent', 0, ['jobid' => $job->id]);
        $maxversion = (int) $DB->get_field('coursegen_blueprint', 'MAX(version)', ['jobid' => $job->id]);
        return (int) $DB->insert_record('coursegen_blueprint', (object) [
            'jobid' => $job->id,
            'version' => $maxversion + 1,
            'iscurrent' => 1,
            'title' => \core_text::substr($blueprint->get_title(), 0, 255),
            'intro' => $blueprint->get_description(),
            'content' => $blueprint->to_json(),
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);
    }

    /**
     * The current blueprint row for a job, if any.
     *
     * @param int $jobid The job id.
     * @return \stdClass|null
     */
    public static function current_record(int $jobid): ?\stdClass {
        global $DB;
        $record = $DB->get_record('coursegen_blueprint', ['jobid' => $jobid, 'iscurrent' => 1]);
        return $record ?: null;
    }

    /**
     * The current blueprint for a job, if any.
     *
     * @param int $jobid The job id.
     * @return blueprint|null
     */
    public static function load_current(int $jobid): ?blueprint {
        $record = self::current_record($jobid);
        return $record ? blueprint::from_json($record->content) : null;
    }
}
