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
 * Tests for the asynchronous corpus extraction task.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\corpus;
use local_coursegen\local\job_manager;
use local_coursegen\task\extract_corpus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for extract_corpus.
 */
#[CoversClass(\local_coursegen\task\extract_corpus::class)]
final class extract_corpus_task_test extends \advanced_testcase {
    /**
     * A file source is extracted, persisted, logged, and the job is finalized.
     *
     * @return void
     */
    public function test_extraction_success(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = $this->category_context();

        $draftid = $this->draft_with('sample.md');
        $jobid = job_manager::create_job($context, $this->admin_id(), 'outlinefirst', null, $draftid);
        $this->run_extraction($jobid);

        $source = $DB->get_record('coursegen_source', ['jobid' => $jobid], '*', MUST_EXIST);
        $this->assertSame(job_manager::SOURCE_EXTRACTED, $source->status);
        $this->assertGreaterThan(0, corpus::from_json($source->corpus)->count());
        $this->assertGreaterThan(0, (int) $source->extractedchars);

        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_EXTRACTED, $job->status);

        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $jobid, 'stage' => 'extract', 'outcome' => 'success']
        ));
    }

    /**
     * A topic-only job is finalized to "extracted" by the task.
     *
     * @return void
     */
    public function test_topic_only_finalized(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $jobid = job_manager::create_job(
            $this->category_context(),
            $this->admin_id(),
            'outlinefirst',
            'A short topic prompt about widgets.',
            null
        );
        $this->run_extraction($jobid);

        $this->assertSame(
            job_manager::STATUS_EXTRACTED,
            $DB->get_field('coursegen_job', 'status', ['id' => $jobid])
        );
    }

    /**
     * Exceeding the corpus token cap fails the job.
     *
     * @return void
     */
    public function test_corpus_token_cap_fails_job(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('max_corpus_tokens', 1, 'local_coursegen');

        $draftid = $this->draft_with('sample.txt');
        $jobid = job_manager::create_job(
            $this->category_context(),
            $this->admin_id(),
            'outlinefirst',
            null,
            $draftid
        );
        $this->run_extraction($jobid);

        $this->assertSame(
            job_manager::STATUS_FAILED,
            $DB->get_field('coursegen_job', 'status', ['id' => $jobid])
        );
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $jobid, 'stage' => 'extract', 'outcome' => 'failure']
        ));
    }

    /**
     * Run extraction for a job (suppressing the task's mtrace output).
     *
     * @param int $jobid The job id.
     * @return void
     */
    private function run_extraction(int $jobid): void {
        $task = new extract_corpus();
        $task->set_custom_data((object) ['jobid' => $jobid]);
        $task->set_userid($this->admin_id());
        ob_start();
        $task->execute();
        ob_end_clean();
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
}
