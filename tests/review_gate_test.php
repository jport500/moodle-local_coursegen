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
 * Tests for the review gate: mode branch, approval, capability, reopen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\job_manager;
use local_coursegen\local\review_gate;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests review_gate.
 */
#[CoversClass(\local_coursegen\local\review_gate::class)]
final class review_gate_test extends \advanced_testcase {
    /**
     * Outline-first holds a blueprinted job for human review.
     *
     * @return void
     */
    public function test_outline_first_holds(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job('outlinefirst', job_manager::STATUS_BLUEPRINTED);

        review_gate::apply_after_generation($job);

        $this->assertSame(
            job_manager::STATUS_AWAITING_REVIEW,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'review']
        ));
    }

    /**
     * Automatic mode auto-approves.
     *
     * @return void
     */
    public function test_automatic_approves(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job('automatic', job_manager::STATUS_BLUEPRINTED);

        review_gate::apply_after_generation($job);

        $this->assertSame(
            job_manager::STATUS_APPROVED,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * The admin lock forces the tenant default over the per-run mode.
     *
     * @return void
     */
    public function test_admin_lock_overrides_run_mode(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('lock_mode', 1, 'local_coursegen');
        set_config('default_mode', 'outlinefirst', 'local_coursegen');
        // Per-run mode says automatic, but the lock forces outline-first.
        $job = $this->job('automatic', job_manager::STATUS_BLUEPRINTED);

        $this->assertSame(review_gate::MODE_OUTLINEFIRST, review_gate::effective_mode($job));
        review_gate::apply_after_generation($job);
        $this->assertSame(
            job_manager::STATUS_AWAITING_REVIEW,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * Approval requires :reviewgate and only from awaiting_review.
     *
     * @return void
     */
    public function test_approve_requires_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job('outlinefirst', job_manager::STATUS_AWAITING_REVIEW);
        $context = \context::instance_by_id($job->contextid);

        // A user with only :generate cannot approve.
        $author = $this->user_with_capabilities($context, ['local/coursegen:generate']);
        try {
            review_gate::approve($job, $author->id);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                job_manager::STATUS_AWAITING_REVIEW,
                $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
            );
        }

        // A reviewer can.
        $reviewer = $this->user_with_capabilities($context, ['local/coursegen:reviewgate']);
        review_gate::approve($job, $reviewer->id);
        $this->assertSame(
            job_manager::STATUS_APPROVED,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * Approval is rejected when the job is not awaiting review.
     *
     * @return void
     */
    public function test_approve_rejects_wrong_status(): void {
        $this->resetAfterTest();
        $job = $this->job('outlinefirst', job_manager::STATUS_BLUEPRINTED);
        $context = \context::instance_by_id($job->contextid);
        $reviewer = $this->user_with_capabilities($context, ['local/coursegen:reviewgate']);

        $this->expectException(\moodle_exception::class);
        review_gate::approve($job, $reviewer->id);
    }

    /**
     * Reopening an approved job sends it back to awaiting_review; others untouched.
     *
     * @return void
     */
    public function test_reopen_if_approved(): void {
        global $DB;
        $this->resetAfterTest();

        $approved = $this->job('outlinefirst', job_manager::STATUS_APPROVED);
        review_gate::reopen_if_approved($approved, (int) $approved->userid);
        $this->assertSame(
            job_manager::STATUS_AWAITING_REVIEW,
            $DB->get_field('coursegen_job', 'status', ['id' => $approved->id])
        );

        $awaiting = $this->job('outlinefirst', job_manager::STATUS_AWAITING_REVIEW);
        review_gate::reopen_if_approved($awaiting, (int) $awaiting->userid);
        $this->assertSame(
            job_manager::STATUS_AWAITING_REVIEW,
            $DB->get_field('coursegen_job', 'status', ['id' => $awaiting->id])
        );
    }

    /**
     * Insert a job with the given mode and status in a fresh category context.
     *
     * @param string $mode The generation mode.
     * @param string $status The job status.
     * @return \stdClass
     */
    private function job(string $mode, string $status): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $id = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => $mode,
            'status' => $status,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        return $DB->get_record('coursegen_job', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a user granted the given capabilities at a context.
     *
     * @param \context $context The context.
     * @param string[] $capabilities Capability names to allow.
     * @return \stdClass The user.
     */
    private function user_with_capabilities(\context $context, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }
}
