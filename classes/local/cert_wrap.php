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
 * Optional cert-chain wrap: place a generated course in a tool_muprog program
 * and, optionally, a tool_mucertify certification (SPEC §9, DECISIONS D10/D16/D17).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * Wraps a materialized course into the program/certification stack when the
 * admin opts in. Pure orchestration — no AI, so it is outside the spend
 * governor. The wrap is best-effort: a failure is logged and the job still
 * completes (the course is the primary artifact). muprog/mucertify are NOT hard
 * dependencies; this checks for them at runtime and skips with a warning when a
 * toggle is on but the plugin (or its API version) is absent. Re-entrancy is by
 * a per-job idnumber: a retry's cleanup deletes the prior program/certification,
 * and find-or-create guards against a leftover if a cleanup was interrupted.
 */
class cert_wrap {
    /** @var string Idnumber prefix that keys a program/certification to a job. */
    public const IDNUMBER_PREFIX = 'coursegen-job-';

    /** @var int Minimum verified tool_muprog version exposing the API we call. */
    public const MUPROG_MIN_VERSION = 2026041950;

    /** @var int Minimum verified tool_mucertify version exposing the API we call. */
    public const MUCERTIFY_MIN_VERSION = 2026041950;

    /**
     * Wrap the course per the admin toggles. Best-effort; never throws.
     *
     * @param \stdClass $job The coursegen job.
     * @param \stdClass $course The materialized course.
     * @param \context_coursecat $context The job's category context.
     * @return void
     */
    public function wrap(\stdClass $job, \stdClass $course, \context_coursecat $context): void {
        $doprogram = (bool) get_config('local_coursegen', 'wrap_muprog');
        $docertification = (bool) get_config('local_coursegen', 'wrap_mucertify');

        // A certification wraps a program — never create one the admin didn't ask
        // for. The settings UI hides this combination; guard it at runtime too.
        if ($docertification && !$doprogram) {
            $this->warn($job, 'wrap_mucertify is on without wrap_muprog; skipping certification (it needs a program)');
            $docertification = false;
        }

        if (!$doprogram) {
            return; // No wrap requested.
        }
        if (!self::program_available()) {
            $this->warn($job, 'wrap_muprog is on but tool_muprog is unavailable; skipping wrap');
            return;
        }

        try {
            $program = $this->ensure_program($job, $course, $context);
        } catch (\Throwable $e) {
            $this->warn($job, 'program wrap failed: ' . $e->getMessage());
            return;
        }
        $this->audit($job, "wrapped course {$course->id} in program {$program->id}", audit_log::SUCCESS);

        if (!$docertification) {
            return;
        }
        if (!self::certification_available()) {
            $this->warn($job, 'wrap_mucertify is on but tool_mucertify is unavailable; skipping certification');
            return;
        }

        try {
            $certification = $this->ensure_certification($job, $course, $context, (int) $program->id);
            // A certification with no allocation source is inert — it looks like it
            // works but can never allocate anyone, so it can never certify (D17). The
            // cert chain is therefore atomic: enable the source or roll the
            // certification back. The program is left intact (it is independently
            // allocatable, not inert).
            $this->enable_certification_allocation_source((int) $program->id);
            $this->audit($job, "wrapped program {$program->id} in certification {$certification->id}", audit_log::SUCCESS);
        } catch (\Throwable $e) {
            $this->rollback_certification($job);
            $this->warn($job, 'certification wrap failed; certification rolled back: ' . $e->getMessage());
        }
    }

    /**
     * Enable the muprog 'mucertify' allocation source on the program so members
     * assigned to the certification are allocated to the program (and enrolled in
     * its course). Idempotent: update_source finds the source by (type, programid)
     * and updates or inserts, so a re-wrap never duplicates it.
     *
     * @param int $programid The program backing the certification.
     * @return void
     */
    protected function enable_certification_allocation_source(int $programid): void {
        \tool_muprog\local\source\mucertify::update_source((object) [
            'programid' => $programid,
            'type' => 'mucertify',
            'enable' => 1,
        ]);
    }

    /**
     * Delete the job's certification (atomic-chain rollback). The program is kept.
     *
     * @param \stdClass $job The coursegen job.
     * @return void
     */
    private function rollback_certification(\stdClass $job): void {
        global $DB;
        if (!self::certification_available()) {
            return;
        }
        $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => self::IDNUMBER_PREFIX . $job->id]);
        if ($cert) {
            \tool_mucertify\local\certification::delete((int) $cert->id);
        }
    }

    /**
     * The wrap's live-state clause for a refusal, or null if absent/empty.
     *
     * A wrap is disposable only while empty: this plugin's cleanup deletes the
     * program/certification by idnumber, and tool_muprog/tool_mucertify delete
     * hard-cascades learner allocations/assignments (and tears down enrolments).
     * So if an admin has populated the wrap, rebuilding would silently wipe their
     * configuration and the cohort's access (D18). Returns just the "what would be
     * lost" clause; the materializer frames and composes it with the course's own
     * learner-state clause into one refusal (D20).
     *
     * @param \stdClass $job The coursegen job.
     * @return string|null The clause, or null if the wrap is absent/empty (safe).
     */
    public function populated_block_reason(\stdClass $job): ?string {
        global $DB;
        $idnumber = self::IDNUMBER_PREFIX . $job->id;
        $reasons = [];

        if (self::program_available()) {
            $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber]);
            if ($program) {
                $allocations = $DB->count_records('tool_muprog_allocation', ['programid' => $program->id]);
                if ($allocations > 0) {
                    $reasons[] = "program \"{$program->fullname}\" (id {$program->id}) has {$allocations} learner allocation(s)";
                }
            }
        }
        if (self::certification_available()) {
            $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => $idnumber]);
            if ($cert) {
                $assignments = $DB->count_records('tool_mucertify_assignment', ['certificationid' => $cert->id]);
                if ($assignments > 0) {
                    $reasons[] = "certification \"{$cert->fullname}\" (id {$cert->id}) has {$assignments} learner assignment(s)";
                }
            }
        }

        return $reasons ? implode('; ', $reasons) : null;
    }

    /**
     * Remove any program/certification this job created (retry idempotency).
     * Certification first, then program — the certification FK points at it.
     *
     * @param \stdClass $job The coursegen job.
     * @return void
     */
    public function cleanup(\stdClass $job): void {
        global $DB;
        $idnumber = self::IDNUMBER_PREFIX . $job->id;

        if (self::certification_available()) {
            $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => $idnumber]);
            if ($cert) {
                \tool_mucertify\local\certification::delete((int) $cert->id);
            }
        }
        if (self::program_available()) {
            $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber]);
            if ($program) {
                \tool_muprog\local\program::delete((int) $program->id);
            }
        }
    }

    /**
     * Find-or-create the job's program and ensure it contains the course once.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $course The course.
     * @param \context_coursecat $context The category context.
     * @return \stdClass The program record.
     */
    protected function ensure_program(\stdClass $job, \stdClass $course, \context_coursecat $context): \stdClass {
        global $DB;
        $idnumber = self::IDNUMBER_PREFIX . $job->id;

        $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber]);
        if (!$program) {
            $program = \tool_muprog\local\program::create((object) [
                'contextid' => $context->id,
                'fullname' => $course->fullname,
                'idnumber' => $idnumber,
            ]);
        }

        // Add the course to the program's content tree exactly once.
        if (!$DB->record_exists('tool_muprog_item', ['programid' => $program->id, 'courseid' => $course->id])) {
            $top = \tool_muprog\local\program::load_content((int) $program->id);
            $top->append_course($top, (int) $course->id);
        }

        return $program;
    }

    /**
     * Find-or-create the job's certification, linked to its program.
     *
     * @param \stdClass $job The job.
     * @param \stdClass $course The course (names the certification).
     * @param \context_coursecat $context The category context.
     * @param int $programid The program to certify.
     * @return \stdClass The certification record.
     */
    protected function ensure_certification(
        \stdClass $job,
        \stdClass $course,
        \context_coursecat $context,
        int $programid
    ): \stdClass {
        global $DB;
        $idnumber = self::IDNUMBER_PREFIX . $job->id;

        $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => $idnumber]);
        if ($cert) {
            return $cert;
        }
        return \tool_mucertify\local\certification::create((object) [
            'contextid' => $context->id,
            'fullname' => $course->fullname,
            'idnumber' => $idnumber,
            'programid1' => $programid,
        ]);
    }

    /**
     * Whether the tool_muprog API we call is present at a supported version.
     *
     * @return bool
     */
    public static function program_available(): bool {
        return class_exists('\tool_muprog\local\program')
            && (int) get_config('tool_muprog', 'version') >= self::MUPROG_MIN_VERSION;
    }

    /**
     * Whether the tool_mucertify API we call is present at a supported version.
     *
     * @return bool
     */
    public static function certification_available(): bool {
        return class_exists('\tool_mucertify\local\certification')
            && (int) get_config('tool_mucertify', 'version') >= self::MUCERTIFY_MIN_VERSION;
    }

    /**
     * Record a §10.2 audit row for a wrap step (no AI tokens involved).
     *
     * @param \stdClass $job The job.
     * @param string $detail Non-sensitive note.
     * @param string $outcome audit_log::SUCCESS or audit_log::FAILURE.
     * @return void
     */
    private function audit(\stdClass $job, string $detail, string $outcome): void {
        audit_log::record((int) $job->id, $job->usermodified ?? null, 'wrap', $outcome, $detail);
    }

    /**
     * Log a best-effort wrap warning (audited and surfaced to cron logs).
     *
     * @param \stdClass $job The job.
     * @param string $detail Non-sensitive note.
     * @return void
     */
    private function warn(\stdClass $job, string $detail): void {
        $this->audit($job, $detail, audit_log::FAILURE);
        mtrace('local_coursegen: ' . $detail);
    }
}
