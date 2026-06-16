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
use local_coursegen\local\review_gate;

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
     * Generate the blueprint, then apply the review-gate mode branch.
     *
     * Structured so "blueprinted" is a clean pass-through: generation runs only
     * from "extracted", and the gate runs whenever the job is "blueprinted" —
     * so if a prior attempt died after the blueprint was stored, a retry still
     * advances it (to awaiting_review or approved) rather than stranding it.
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

        if ($job->status === job_manager::STATUS_EXTRACTED) {
            if (!(new blueprint_generator($this->get_text_client()))->generate_for_job($job)) {
                return; // The generator already failed and logged the job.
            }
            $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        }

        if ($job->status === job_manager::STATUS_BLUEPRINTED) {
            review_gate::apply_after_generation($job);
        } else {
            mtrace("local_coursegen: job {$jobid} not ready for the gate ({$job->status}); skipping.");
        }
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
