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
 * Tests for the P12 wayfinding logic: status->phase dispatch, hub job query,
 * and the failure-reason lookup.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\audit_log;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\job_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Covers the pure logic behind the dispatching hub and job page.
 */
#[CoversClass(\local_coursegen\local\job_manager::class)]
final class wayfinding_test extends \advanced_testcase {
    /**
     * Every status maps to the correct wayfinding phase, in both modes.
     *
     * @return void
     */
    public function test_classify_status(): void {
        $processing = [
            job_manager::STATUS_EXTRACTING,
            job_manager::STATUS_EXTRACTED,
            job_manager::STATUS_BLUEPRINTED,
            job_manager::STATUS_APPROVED,
            job_manager::STATUS_MATERIALIZING,
        ];
        foreach ($processing as $status) {
            $this->assertSame(job_manager::PHASE_PROCESSING, job_manager::classify_status($status), $status);
        }
        $this->assertSame(
            job_manager::PHASE_REVIEW,
            job_manager::classify_status(job_manager::STATUS_AWAITING_REVIEW)
        );
        $this->assertSame(
            job_manager::PHASE_COMPLETE,
            job_manager::classify_status(job_manager::STATUS_COMPLETE)
        );
        $this->assertSame(
            job_manager::PHASE_FAILED,
            job_manager::classify_status(job_manager::STATUS_FAILED)
        );
    }

    /**
     * The hub query returns the category's jobs newest-first with their current
     * blueprint title, and excludes jobs from other categories.
     *
     * @return void
     */
    public function test_jobs_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $cat = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($cat->id);
        $other = \context_coursecat::instance($this->getDataGenerator()->create_category()->id);

        // Older job, no blueprint yet.
        $older = $this->insert_job($context->id, job_manager::STATUS_EXTRACTING, 1000);
        // Newer job with a current blueprint (so it has a title).
        $newer = $this->insert_job($context->id, job_manager::STATUS_AWAITING_REVIEW, 2000);
        $job = $DB->get_record('coursegen_job', ['id' => $newer], '*', MUST_EXIST);
        blueprint_store::save_new_version(
            $job,
            blueprint::from_array(['title' => 'Widgets 101', 'description' => 'd', 'sections' => [['title' => 'A']]]),
            2
        );
        // A job in a different category must not appear.
        $this->insert_job($other->id, job_manager::STATUS_COMPLETE, 3000);

        $rows = job_manager::jobs_in_context($context->id);

        $this->assertSame([$newer, $older], array_keys($rows), 'Wrong jobs or order.');
        $this->assertSame('Widgets 101', $rows[$newer]->title);
        $this->assertNull($rows[$older]->title);
    }

    /**
     * The failure reason is the most recent failure detail for the job; success
     * rows and other jobs are ignored.
     *
     * @return void
     */
    public function test_failure_reason(): void {
        $this->resetAfterTest();
        $cat = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($cat->id);
        $jobid = $this->insert_job($context->id, job_manager::STATUS_FAILED, 1000);

        $this->assertNull(job_manager::failure_reason($jobid));

        audit_log::record($jobid, null, 'materialize', audit_log::SUCCESS, 'all good');
        audit_log::record($jobid, null, 'materialize', audit_log::FAILURE, 'first failure');
        audit_log::record($jobid, null, 'materialize', audit_log::FAILURE, 'latest failure');

        $this->assertSame('latest failure', job_manager::failure_reason($jobid));
    }

    /**
     * Insert a bare job row in a context with a fixed timemodified (for ordering).
     *
     * @param int $contextid The category context id.
     * @param string $status The job status.
     * @param int $time The timecreated/timemodified value.
     * @return int The new job id.
     */
    private function insert_job(int $contextid, string $status, int $time): int {
        global $DB;
        return (int) $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $contextid,
            'courseid' => null,
            'mode' => 'outlinefirst',
            'status' => $status,
            'timecreated' => $time,
            'timemodified' => $time,
            'usermodified' => 2,
        ]);
    }
}
