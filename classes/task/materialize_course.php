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
 * Adhoc task that materializes an approved job into a hidden course.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\task;

use local_coursegen\local\ai\core_ai_image_client;
use local_coursegen\local\ai\core_ai_text_client;
use local_coursegen\local\ai\image_client;
use local_coursegen\local\ai\quiz_client;
use local_coursegen\local\ai\quizgenpro_quiz_client;
use local_coursegen\local\ai\text_client;
use local_coursegen\local\job_manager;
use local_coursegen\local\materializer;

/**
 * Queued when a job reaches "approved" (auto in automatic mode, or on manual
 * approval). Carries the requesting user's id so creation and AI calls run in
 * the correct tenant/user context (SPEC §10.6).
 */
class materialize_course extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_materializecourse', 'local_coursegen');
    }

    /**
     * Materialize the job named in the custom data.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid]);
        if (!$job) {
            mtrace("local_coursegen: job {$jobid} not found; skipping materialization.");
            return;
        }
        // Accept a fresh approval or a retry of an attempt that died mid-run.
        if (
            $job->status !== job_manager::STATUS_APPROVED
                && $job->status !== job_manager::STATUS_MATERIALIZING
        ) {
            mtrace("local_coursegen: job {$jobid} not materializable ({$job->status}); skipping.");
            return;
        }

        (new materializer($this->get_text_client(), $this->get_image_client(), $this->get_quiz_client()))
            ->materialize($job);
    }

    /**
     * The text client. Overridable in tests to inject a stub.
     *
     * @return text_client
     */
    protected function get_text_client(): text_client {
        return new core_ai_text_client();
    }

    /**
     * The image client. Overridable in tests to inject a stub.
     *
     * @return image_client
     */
    protected function get_image_client(): image_client {
        return new core_ai_image_client();
    }

    /**
     * The quiz client. Overridable in tests to inject a stub.
     *
     * @return quiz_client
     */
    protected function get_quiz_client(): quiz_client {
        return new quizgenpro_quiz_client();
    }
}
