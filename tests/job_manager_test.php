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
 * Tests for job creation, source attachment and limit enforcement.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\job_manager;
use local_coursegen\task\extract_corpus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the job_manager ingestion API.
 */
#[CoversClass(\local_coursegen\local\job_manager::class)]
final class job_manager_test extends \advanced_testcase {
    /**
     * A topic-only job creates a trivial extracted corpus and queues the task.
     *
     * @return void
     */
    public function test_topic_only_job(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->category_context();

        $jobid = job_manager::create_job(
            $context,
            $this->admin_id(),
            'outlinefirst',
            'Introduction to widgets.',
            null
        );

        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_EXTRACTING, $job->status);

        $sources = $DB->get_records('coursegen_source', ['jobid' => $jobid]);
        $this->assertCount(1, $sources);
        $source = reset($sources);
        $this->assertSame('topic', $source->type);
        $this->assertSame(job_manager::SOURCE_EXTRACTED, $source->status);
        $this->assertNotEmpty($source->corpus);

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(extract_corpus::class));
    }

    /**
     * The operator depth controls (D26) round-trip: explicit values persist,
     * and missing or unknown values clamp to the house defaults.
     *
     * @return void
     */
    public function test_depth_controls_round_trip_and_clamp(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->category_context();

        // Explicit values persist verbatim.
        $jobid = job_manager::create_job(
            $context,
            $this->admin_id(),
            'outlinefirst',
            'Advanced widgets.',
            null,
            'advanced',
            'comprehensive'
        );
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('advanced', $job->audiencelevel);
        $this->assertSame('comprehensive', $job->depth);

        // Omitted values fall back to the defaults.
        $jobid = job_manager::create_job($context, $this->admin_id(), 'outlinefirst', 'Default widgets.', null);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('intermediate', $job->audiencelevel);
        $this->assertSame('standard', $job->depth);

        // Unknown values are clamped, never stored.
        $jobid = job_manager::create_job(
            $context,
            $this->admin_id(),
            'outlinefirst',
            'Junk widgets.',
            null,
            'wizard',
            'epic'
        );
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('intermediate', $job->audiencelevel);
        $this->assertSame('standard', $job->depth);
    }

    /**
     * An uploaded file becomes a pending source with the file in the permanent area.
     *
     * @return void
     */
    public function test_job_with_uploaded_file(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->category_context();

        $draftid = $this->draft_with('sample.docx');
        $jobid = job_manager::create_job($context, $this->admin_id(), 'automatic', null, $draftid);

        $source = $DB->get_record('coursegen_source', ['jobid' => $jobid], '*', MUST_EXIST);
        $this->assertSame('docx', $source->type);
        $this->assertSame(job_manager::SOURCE_PENDING, $source->status);
        $this->assertNull($source->corpus);
        $this->assertCount(1, job_manager::stored_source_files($context, $jobid));
    }

    /**
     * Exceeding the per-job byte cap is rejected.
     *
     * @return void
     */
    public function test_source_byte_limit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('max_source_bytes', 10, 'local_coursegen');
        $context = $this->category_context();
        $draftid = $this->draft_with('sample.pdf');

        $this->expectException(\moodle_exception::class);
        job_manager::create_job($context, $this->admin_id(), 'outlinefirst', null, $draftid);
    }

    /**
     * Unsupported file types are rejected.
     *
     * @return void
     */
    public function test_unsupported_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->category_context();
        $draftid = $this->draft_with_string('archive.zip', 'not a real archive');

        $this->expectException(\moodle_exception::class);
        job_manager::create_job($context, $this->admin_id(), 'outlinefirst', null, $draftid);
    }

    /**
     * A job with neither topic nor files is rejected.
     *
     * @return void
     */
    public function test_empty_job_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        job_manager::create_job($this->category_context(), $this->admin_id(), 'outlinefirst', '  ', null);
    }

    /**
     * A fresh category context.
     *
     * @return \context_coursecat
     */
    private function category_context(): \context_coursecat {
        $category = $this->getDataGenerator()->create_category();
        return \context_coursecat::instance($category->id);
    }

    /**
     * The admin user id.
     *
     * @return int
     */
    private function admin_id(): int {
        global $USER;
        return (int) $USER->id;
    }

    /**
     * Create a draft area containing a fixture file.
     *
     * @param string $name Fixture filename.
     * @return int Draft item id.
     */
    private function draft_with(string $name): int {
        global $USER;
        $fs = get_file_storage();
        $draftid = file_get_unused_draft_itemid();
        $fs->create_file_from_pathname((object) [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $name,
        ], __DIR__ . '/fixtures/' . $name);
        return $draftid;
    }

    /**
     * Create a draft area containing a file built from a string.
     *
     * @param string $name The filename.
     * @param string $content The file content.
     * @return int Draft item id.
     */
    private function draft_with_string(string $name, string $content): int {
        global $USER;
        $fs = get_file_storage();
        $draftid = file_get_unused_draft_itemid();
        $fs->create_file_from_string((object) [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $name,
        ], $content);
        return $draftid;
    }
}
