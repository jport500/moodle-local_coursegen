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
 * Adhoc task that generates the blueprint for a job.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\task;

use local_coursegen\local\ai\core_ai_text_client;
use local_coursegen\local\ai\text_client;
use local_coursegen\local\blueprint_generator;
use local_coursegen\local\job_manager;

/**
 * Queued after extraction completes. Carries the requesting user's id so the
 * reasoning call runs in the correct tenant/user context (SPEC §10.6).
 */
class generate_blueprint extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_generateblueprint', 'local_coursegen');
    }

    /**
     * Generate the blueprint for the job named in the custom data.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid]);
        if (!$job) {
            mtrace("local_coursegen: job {$jobid} not found; skipping blueprint.");
            return;
        }
        if ($job->status !== job_manager::STATUS_EXTRACTED) {
            mtrace("local_coursegen: job {$jobid} not in 'extracted' state ({$job->status}); skipping blueprint.");
            return;
        }

        (new blueprint_generator($this->get_text_client()))->generate_for_job($job);
    }

    /**
     * The reasoning-tier client. Overridable in tests to inject a stub.
     *
     * @return text_client
     */
    protected function get_text_client(): text_client {
        return new core_ai_text_client();
    }
}
