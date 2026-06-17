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
 * Tests for the optional cert-chain wrap (DECISIONS D17).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\cert_wrap;
use local_coursegen\local\job_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises the wrap against the real tool_muprog / tool_mucertify APIs: it
 * places the course in a program and links a certification, honours the toggles
 * and their dependency, and a retry (cleanup + re-wrap) never duplicates.
 */
#[CoversClass(\local_coursegen\local\cert_wrap::class)]
final class cert_wrap_test extends \advanced_testcase {
    /**
     * Skip the whole case unless the cert stack is installed on this site.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!cert_wrap::program_available() || !cert_wrap::certification_available()) {
            $this->markTestSkipped('tool_muprog / tool_mucertify not available.');
        }
    }

    /**
     * Both toggles on: a program contains the course and a certification links it.
     *
     * @return void
     */
    public function test_wrap_creates_program_and_certification(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        (new cert_wrap())->wrap($job, $course, $context);

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $this->assertTrue(
            $DB->record_exists(
                'tool_muprog_item',
                ['programid' => $program->id, 'courseid' => $course->id]
            ),
            'Course was not added to the program content tree.'
        );

        $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $this->assertEquals(
            $program->id,
            $cert->programid1,
            'Certification is not linked to the program.'
        );
    }

    /**
     * Only the program toggle: a program is created, no certification.
     *
     * @return void
     */
    public function test_program_only(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 0, 'local_coursegen');

        (new cert_wrap())->wrap($job, $course, $context);

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $this->assertTrue($DB->record_exists('tool_muprog_program', ['idnumber' => $idnumber]));
        $this->assertFalse($DB->record_exists('tool_mucertify_certification', ['idnumber' => $idnumber]));
    }

    /**
     * Neither toggle: nothing is created.
     *
     * @return void
     */
    public function test_no_wrap_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 0, 'local_coursegen');
        set_config('wrap_mucertify', 0, 'local_coursegen');

        (new cert_wrap())->wrap($job, $course, $context);

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $this->assertFalse($DB->record_exists('tool_muprog_program', ['idnumber' => $idnumber]));
        $this->assertFalse($DB->record_exists('tool_mucertify_certification', ['idnumber' => $idnumber]));
    }

    /**
     * Certification toggle on without the program toggle: skipped, with a warning,
     * and no program is silently created.
     *
     * @return void
     */
    public function test_certification_without_program_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 0, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        ob_start(); // The misconfiguration warning emits an mtrace line.
        (new cert_wrap())->wrap($job, $course, $context);
        ob_end_clean();

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $this->assertFalse($DB->record_exists('tool_muprog_program', ['idnumber' => $idnumber]));
        $this->assertFalse($DB->record_exists('tool_mucertify_certification', ['idnumber' => $idnumber]));
        $this->assertTrue(
            $DB->record_exists_select(
                'coursegen_log',
                'jobid = :jobid AND stage = :stage AND outcome = :outcome',
                ['jobid' => $job->id, 'stage' => 'wrap', 'outcome' => 'failure']
            ),
            'The cert-without-program misconfiguration was not warned.'
        );
    }

    /**
     * Re-wrapping without cleanup reuses, never duplicates (find-or-create).
     *
     * @return void
     */
    public function test_idempotent_rewrap_does_not_duplicate(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        $wrap = new cert_wrap();
        $wrap->wrap($job, $course, $context);
        $wrap->wrap($job, $course, $context);

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $this->assertEquals(1, $DB->count_records('tool_muprog_program', ['idnumber' => $idnumber]));
        $this->assertEquals(1, $DB->count_records('tool_mucertify_certification', ['idnumber' => $idnumber]));
        $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $this->assertEquals(
            1,
            $DB->count_records(
                'tool_muprog_item',
                ['programid' => $program->id, 'courseid' => $course->id]
            ),
            'The course was appended to the program more than once.'
        );
    }

    /**
     * A retry (cleanup then wrap) leaves exactly one program and certification,
     * linked to the rebuilt program — never a stranded or duplicate artifact.
     *
     * @return void
     */
    public function test_cleanup_then_rewrap_is_single_and_relinked(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        $wrap = new cert_wrap();
        $wrap->wrap($job, $course, $context);
        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $firstprogramid = (int) $DB->get_field('tool_muprog_program', 'id', ['idnumber' => $idnumber]);

        // Simulate the materialize retry: cleanup removes the prior wrap, re-wrap.
        $wrap->cleanup($job);
        $this->assertFalse($DB->record_exists('tool_muprog_program', ['idnumber' => $idnumber]));
        $this->assertFalse($DB->record_exists('tool_mucertify_certification', ['idnumber' => $idnumber]));

        $wrap->wrap($job, $course, $context);

        $this->assertEquals(1, $DB->count_records('tool_muprog_program', ['idnumber' => $idnumber]));
        $this->assertEquals(1, $DB->count_records('tool_mucertify_certification', ['idnumber' => $idnumber]));
        $newprogram = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $this->assertNotEquals($firstprogramid, $newprogram->id, 'Cleanup did not rebuild the program.');
        $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $this->assertEquals($newprogram->id, $cert->programid1, 'Certification not relinked to the rebuilt program.');
    }

    /**
     * The populated-block predicate is null when no wrap exists or it is empty.
     *
     * @return void
     */
    public function test_populated_block_reason_null_when_empty(): void {
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        $wrap = new cert_wrap();

        // No wrap yet.
        $this->assertNull($wrap->populated_block_reason($job));

        // Wrapped but unpopulated — still safe to rebuild.
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');
        $wrap->wrap($job, $course, $context);
        $this->assertNull($wrap->populated_block_reason($job));
    }

    /**
     * A learner allocation on the program makes the predicate return a reason
     * that names the program and the count.
     *
     * @return void
     */
    public function test_populated_block_reason_detects_allocation(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 0, 'local_coursegen');
        $wrap = new cert_wrap();
        $wrap->wrap($job, $course, $context);

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $learner = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('tool_muprog_allocation', (object) [
            'programid' => $program->id, 'userid' => $learner->id, 'sourceid' => 0, 'archived' => 0,
            'timeallocated' => $now, 'timestart' => $now, 'calendarupdated' => 0,
            'itemscompleted' => 0, 'timecreated' => $now,
        ]);

        $reason = $wrap->populated_block_reason($job);
        $this->assertNotNull($reason);
        $this->assertStringContainsString((string) $program->id, $reason);
        $this->assertStringContainsString('1 learner allocation', $reason);
    }

    /**
     * Best-effort: when the certification step throws after the program is created,
     * the wrap surfaces the partial (program kept, warning logged) and does not
     * propagate the exception — so the caller still completes the job.
     *
     * @return void
     */
    public function test_best_effort_partial_when_certification_fails(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        $wrap = new class extends cert_wrap {
            /**
             * Force the certification step to fail after the program is created.
             *
             * @param \stdClass $job The job.
             * @param \stdClass $course The course.
             * @param \context_coursecat $context The category context.
             * @param int $programid The program id.
             * @return \stdClass Never returns.
             */
            protected function ensure_certification(
                \stdClass $job,
                \stdClass $course,
                \context_coursecat $context,
                int $programid
            ): \stdClass {
                throw new \RuntimeException('forced certification failure');
            }
        };

        ob_start(); // The best-effort warning emits an mtrace line.
        $wrap->wrap($job, $course, $context); // Must not throw.
        ob_end_clean();

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $this->assertTrue(
            $DB->record_exists('tool_muprog_program', ['idnumber' => $idnumber]),
            'The program (created before the failure) was not kept.'
        );
        $this->assertFalse($DB->record_exists('tool_mucertify_certification', ['idnumber' => $idnumber]));
        $this->assertTrue(
            $DB->record_exists_select(
                'coursegen_log',
                'jobid = :jobid AND stage = :stage AND outcome = :outcome AND ' . $DB->sql_like('detail', ':detail'),
                ['jobid' => $job->id, 'stage' => 'wrap', 'outcome' => 'failure', 'detail' => '%certification wrap failed%']
            ),
            'The partial wrap failure was not surfaced.'
        );
    }

    /**
     * The SEAM (where P7 missed): once wrapped, assigning a learner to the
     * certification actually allocates them to the program and enrols them in the
     * course — proving the mucertify allocation source is wired, not just present.
     *
     * @return void
     */
    public function test_assignment_allocates_and_enrols_learner(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        (new cert_wrap())->wrap($job, $course, $context);
        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $program = $DB->get_record('tool_muprog_program', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $cert = $DB->get_record('tool_mucertify_certification', ['idnumber' => $idnumber], '*', MUST_EXIST);

        // The program now carries the mucertify allocation source.
        $this->assertTrue($DB->record_exists(
            'tool_muprog_source',
            ['programid' => $program->id, 'type' => 'mucertify']
        ));

        // Assign a learner via a manual assignment source on the certification.
        $source = \tool_mucertify\local\source\manual::update_source((object) [
            'certificationid' => $cert->id, 'type' => 'manual', 'enable' => 1,
        ]);
        $learner = $this->getDataGenerator()->create_user();
        \tool_mucertify\local\source\manual::assign_users($cert->id, (int) $source->id, [$learner->id]);

        // The assignment must flow through to a program allocation AND a course enrolment.
        $this->assertTrue(
            $DB->record_exists(
                'tool_muprog_allocation',
                ['programid' => $program->id, 'userid' => $learner->id]
            ),
            'Assigning the learner to the certification did not allocate them to the program.'
        );
        $this->assertTrue(
            is_enrolled(\context_course::instance($course->id), $learner),
            'Allocated learner was not enrolled in the wrapped course.'
        );
    }

    /**
     * Re-wrapping does not duplicate the mucertify allocation source.
     *
     * @return void
     */
    public function test_allocation_source_idempotent_on_rewrap(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        $wrap = new cert_wrap();
        $wrap->wrap($job, $course, $context);
        $wrap->wrap($job, $course, $context);

        $program = $DB->get_record(
            'tool_muprog_program',
            ['idnumber' => cert_wrap::IDNUMBER_PREFIX . $job->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(1, $DB->count_records(
            'tool_muprog_source',
            ['programid' => $program->id, 'type' => 'mucertify']
        ));
    }

    /**
     * Atomic cert chain: if the allocation source can't be enabled, the
     * certification is rolled back (never inert), the failure is audited, and the
     * program is left intact.
     *
     * @return void
     */
    public function test_certification_rolled_back_when_source_fails(): void {
        global $DB;
        $this->resetAfterTest();
        [$job, $course, $context] = $this->scenario();
        set_config('wrap_muprog', 1, 'local_coursegen');
        set_config('wrap_mucertify', 1, 'local_coursegen');

        $wrap = new class extends cert_wrap {
            /**
             * Force the allocation-source enabling to fail.
             *
             * @param int $programid The program id.
             * @return void
             */
            protected function enable_certification_allocation_source(int $programid): void {
                throw new \moodle_exception('error');
            }
        };

        ob_start(); // The rollback warning emits an mtrace line.
        $wrap->wrap($job, $course, $context);
        ob_end_clean();

        $idnumber = cert_wrap::IDNUMBER_PREFIX . $job->id;
        $this->assertFalse(
            $DB->record_exists('tool_mucertify_certification', ['idnumber' => $idnumber]),
            'An inert certification was left after the source failed.'
        );
        $this->assertTrue(
            $DB->record_exists('tool_muprog_program', ['idnumber' => $idnumber]),
            'The program should be kept when only the certification rolls back.'
        );
        $this->assertTrue(
            $DB->record_exists_select(
                'coursegen_log',
                'jobid = :jobid AND stage = :stage AND outcome = :outcome AND ' . $DB->sql_like('detail', ':detail'),
                ['jobid' => $job->id, 'stage' => 'wrap', 'outcome' => 'failure', 'detail' => '%rolled back%']
            ),
            'The rolled-back certification was not audited as a failure.'
        );
    }

    /**
     * Build a job + a hidden course in a category, plus that category's context.
     *
     * @return array [job, course, context]
     */
    private function scenario(): array {
        global $DB;
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $course = $this->getDataGenerator()->create_course(
            ['category' => $category->id, 'visible' => 0, 'fullname' => 'Generated Course']
        );

        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => $course->id,
            'mode' => 'automatic',
            'status' => job_manager::STATUS_MATERIALIZING,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        return [$job, $course, $context];
    }
}
