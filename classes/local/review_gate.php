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
 * The human review gate over the generated blueprint.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * Applies the mode branch after a blueprint exists and gates approval
 * (SPEC §6, D3). Outline-first holds the job at awaiting_review for a human;
 * automatic auto-approves. Approval requires local/coursegen:reviewgate.
 * Every transition is recorded in coursegen_log.
 */
class review_gate {
    /** @var string Outline-first mode value. */
    public const MODE_OUTLINEFIRST = 'outlinefirst';

    /** @var string Automatic mode value. */
    public const MODE_AUTOMATIC = 'automatic';

    /**
     * The effective mode for a job: the admin lock forces the tenant default,
     * otherwise the job's own (per-run) mode applies.
     *
     * @param \stdClass $job The coursegen_job row.
     * @return string One of the MODE_* values.
     */
    public static function effective_mode(\stdClass $job): string {
        if (get_config('local_coursegen', 'lock_mode')) {
            return get_config('local_coursegen', 'default_mode') === self::MODE_AUTOMATIC
                ? self::MODE_AUTOMATIC : self::MODE_OUTLINEFIRST;
        }
        return $job->mode === self::MODE_AUTOMATIC ? self::MODE_AUTOMATIC : self::MODE_OUTLINEFIRST;
    }

    /**
     * Branch a freshly blueprinted job: automatic mode auto-approves; outline-
     * first holds it for review. Idempotent and system-driven (no capability
     * check), so it can be safely re-run if a prior task attempt died after the
     * blueprint was stored — keeping "blueprinted" a clean pass-through.
     *
     * @param \stdClass $job The coursegen_job row (status: blueprinted).
     * @return void
     */
    public static function apply_after_generation(\stdClass $job): void {
        if (self::effective_mode($job) === self::MODE_AUTOMATIC) {
            self::transition(
                $job,
                job_manager::STATUS_APPROVED,
                (int) $job->userid,
                'auto-approved (automatic mode)'
            );
            self::queue_materialization($job);
        } else {
            self::transition(
                $job,
                job_manager::STATUS_AWAITING_REVIEW,
                (int) $job->userid,
                'awaiting review (outline-first)'
            );
        }
    }

    /**
     * Approve the current blueprint, advancing the job to materialize-ready.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param int $userid The approving user (capability is checked against them).
     * @return void
     * @throws \required_capability_exception If the user lacks :reviewgate.
     * @throws \moodle_exception If the job is not awaiting review.
     */
    public static function approve(\stdClass $job, int $userid): void {
        $context = \context::instance_by_id($job->contextid);
        require_capability('local/coursegen:reviewgate', $context, $userid);
        if ($job->status !== job_manager::STATUS_AWAITING_REVIEW) {
            throw new \moodle_exception('error_notawaitingreview', 'local_coursegen');
        }
        self::transition($job, job_manager::STATUS_APPROVED, $userid, 'approved');
        self::queue_materialization($job);
    }

    /**
     * Queue the adhoc task that materializes an approved job, carrying the
     * acting user's id for tenant/user context.
     *
     * @param \stdClass $job The approved job.
     * @return void
     */
    private static function queue_materialization(\stdClass $job): void {
        $task = new \local_coursegen\task\materialize_course();
        $task->set_custom_data((object) ['jobid' => $job->id]);
        $task->set_userid((int) $job->userid);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Reopen review when an edit/regeneration changes a job that has already been
     * approved or materialized, so the changed content must be re-approved before
     * it takes effect. Covers both APPROVED (no approved job points at an
     * unreviewed version) and COMPLETE (an edit to a built course re-drives it
     * through review → re-approval → re-materialize; without this the saved edit
     * would never reach the live course).
     *
     * @param \stdClass $job The coursegen_job row.
     * @param int $userid The editing user.
     * @return void
     */
    public static function reopen_for_reedit(\stdClass $job, int $userid): void {
        if (
            $job->status === job_manager::STATUS_APPROVED
                || $job->status === job_manager::STATUS_COMPLETE
        ) {
            self::transition(
                $job,
                job_manager::STATUS_AWAITING_REVIEW,
                $userid,
                'reopened after edit; re-approval required'
            );
        }
    }

    /**
     * Persist a status transition and audit it.
     *
     * @param \stdClass $job The coursegen_job row (updated in place).
     * @param string $status The new status.
     * @param int $userid The user responsible.
     * @param string $detail Non-sensitive note.
     * @return void
     */
    private static function transition(\stdClass $job, string $status, int $userid, string $detail): void {
        global $DB;
        $DB->set_field('coursegen_job', 'status', $status, ['id' => $job->id]);
        $DB->set_field('coursegen_job', 'timemodified', time(), ['id' => $job->id]);
        $job->status = $status;
        audit_log::record((int) $job->id, $userid, 'review', audit_log::SUCCESS, $detail);
    }
}
