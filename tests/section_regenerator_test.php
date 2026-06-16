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
 * Tests for per-section blueprint regeneration (offline, stubbed client).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\stub_text_client;
use local_coursegen\local\ai\text_result;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\job_manager;
use local_coursegen\local\section_regenerator;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_text_client.php');

/**
 * Tests section_regenerator.
 */
#[CoversClass(\local_coursegen\local\section_regenerator::class)]
final class section_regenerator_test extends \advanced_testcase {
    /**
     * A successful regeneration stores a new version and logs the call (§10.2).
     *
     * @return void
     */
    public function test_regenerate_success(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job_with_blueprint(job_manager::STATUS_AWAITING_REVIEW);

        $stub = new stub_text_client([$this->ok($this->section_json('Rewritten Intro'))]);
        $ok = (new section_regenerator($stub))->regenerate($job, 0, 2);

        $this->assertTrue($ok);
        $current = blueprint_store::current_record($job->id);
        $this->assertEquals(2, $current->version);
        $this->assertEquals(1, $current->iscurrent);
        $blueprint = blueprint::from_json($current->content);
        $this->assertSame('Rewritten Intro', $blueprint->get_sections()[0]['title']);
        $this->assertSame('Second', $blueprint->get_sections()[1]['title']);

        $log = $DB->get_record(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'blueprint', 'outcome' => 'success'],
            '*',
            MUST_EXIST
        );
        $this->assertSame('generate_text', $log->actionname);
        $this->assertSame('stubprovider', $log->provider);
        $this->assertSame('stub-model', $log->model);
        $this->assertStringContainsString('regenerate section 0', $log->detail);
    }

    /**
     * Regenerating on an approved job reopens it for re-approval.
     *
     * @return void
     */
    public function test_regenerate_reopens_approved(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job_with_blueprint(job_manager::STATUS_APPROVED);

        $stub = new stub_text_client([$this->ok($this->section_json('Rewritten'))]);
        $this->assertTrue((new section_regenerator($stub))->regenerate($job, 0, 2));

        $this->assertSame(
            job_manager::STATUS_AWAITING_REVIEW,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * Invalid JSON output fails without storing a new version, and is logged.
     *
     * @return void
     */
    public function test_regenerate_invalid_json(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job_with_blueprint(job_manager::STATUS_AWAITING_REVIEW);

        $stub = new stub_text_client([$this->ok('not json')]);
        $this->assertFalse((new section_regenerator($stub))->regenerate($job, 0, 2));

        // No new version is stored when the output is unusable...
        $this->assertEquals(1, blueprint_store::current_record($job->id)->version);
        // ...but the provider call itself is still audited (§10.2).
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'blueprint', 'provider' => 'stubprovider']
        ));
    }

    /**
     * An out-of-range index regenerates nothing and makes no call.
     *
     * @return void
     */
    public function test_regenerate_bad_index(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job_with_blueprint(job_manager::STATUS_AWAITING_REVIEW);

        $stub = new stub_text_client([$this->ok($this->section_json('X'))]);
        $this->assertFalse((new section_regenerator($stub))->regenerate($job, 99, 2));

        $this->assertEquals(1, blueprint_store::current_record($job->id)->version);
        $this->assertSame(0, $DB->count_records('coursegen_log', ['jobid' => $job->id]));
    }

    /**
     * Create a job with a stored two-section blueprint.
     *
     * @param string $status The job status.
     * @return \stdClass The job.
     */
    private function job_with_blueprint(string $status): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'outlinefirst',
            'status' => $status,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $blueprint = blueprint::from_array([
            'title' => 'Course',
            'description' => 'Desc',
            'sections' => [
                ['title' => 'First', 'objectives' => ['a']],
                ['title' => 'Second', 'objectives' => ['b']],
            ],
        ]);
        blueprint_store::save_new_version($job, $blueprint, 2);
        return $job;
    }

    /**
     * A successful text_result wrapping the given content.
     *
     * @param string $content The content.
     * @return text_result
     */
    private function ok(string $content): text_result {
        return new text_result(
            success: true,
            content: $content,
            model: 'stub-model',
            provider: 'stubprovider',
            prompttokens: 5,
            completiontokens: 7,
        );
    }

    /**
     * A single-section JSON document with the given title.
     *
     * @param string $title The section title.
     * @return string
     */
    private function section_json(string $title): string {
        return json_encode([
            'title' => $title,
            'objectives' => ['Understand the topic'],
            'contenttype' => 'page',
            'summary' => 'A summary',
            'image' => ['generate' => true, 'prompthint' => 'diagram'],
            'assessment' => ['type' => 'quiz', 'questioncount' => 2],
        ]);
    }
}
