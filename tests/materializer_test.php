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
 * Tests for materialization (offline, stubbed text + image clients).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\stub_image_client;
use local_coursegen\local\ai\stub_text_client;
use local_coursegen\local\ai\text_result;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\job_manager;
use local_coursegen\local\materializer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_text_client.php');
require_once(__DIR__ . '/fixtures/stub_image_client.php');

/**
 * Tests materializer.
 */
#[CoversClass(\local_coursegen\local\materializer::class)]
final class materializer_test extends \advanced_testcase {
    /**
     * A hidden pathway course is built with a label per section, an embedded
     * image where flagged, spend accrued, and the job advanced to complete.
     *
     * @return void
     */
    public function test_materializes_hidden_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job();

        $image = new stub_image_client(true);
        $ok = (new materializer($this->text(), $image))->materialize($job);
        $this->assertTrue($ok);

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertNotEmpty($job->courseid);

        $course = $DB->get_record('course', ['id' => $job->courseid], '*', MUST_EXIST);
        $this->assertEquals(0, $course->visible);
        $this->assertSame('pathway', $course->format);
        $this->assertEquals(1, $course->enablecompletion);

        // One label per section; the flagged section embeds an image.
        $this->assertEquals(2, $DB->count_records('label', ['course' => $course->id]));
        $this->assertEquals(1, $image->call_count());
        $this->assertTrue($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':img'),
            ['c' => $course->id, 'img' => '%<img%']
        ));

        // The embedded image is stored in a label's intro file area.
        $hasfile = false;
        foreach (get_fast_modinfo($course->id)->get_instances_of('label') as $cm) {
            if (get_file_storage()->get_area_files($cm->context->id, 'mod_label', 'intro', 0, 'id', false)) {
                $hasfile = true;
            }
        }
        $this->assertTrue($hasfile);

        // Spend accrued from the §10.2 token logs, and an image call recorded.
        $this->assertGreaterThan(0, (int) $job->actualspend);
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'materialize', 'actionname' => 'generate_image', 'imagecount' => 1]
        ));
    }

    /**
     * An estimate over the remaining spend cap hard-stops before any course.
     *
     * @return void
     */
    public function test_over_spend_cap_hard_stops(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('cap_period_spend', 1, 'local_coursegen');
        $job = $this->approved_job();

        ob_start();
        $ok = (new materializer($this->text(), new stub_image_client(true)))->materialize($job);
        ob_end_clean();

        $this->assertFalse($ok);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
        $this->assertEmpty($job->courseid);
        $this->assertEquals(0, $DB->count_records('course', ['shortname' => 'coursegen-' . $job->id]));
    }

    /**
     * With no image budget, the course still builds but without images.
     *
     * @return void
     */
    public function test_image_subcap_skips_images(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Image budget of 1, already fully consumed this period.
        set_config('cap_image_count', 1, 'local_coursegen');
        $job = $this->approved_job();
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id, 'stage' => 'materialize', 'imagecount' => 1,
            'outcome' => 'success', 'timecreated' => time(),
        ]);

        $image = new stub_image_client(true);
        $this->assertTrue((new materializer($this->text(), $image))->materialize($job));

        $this->assertEquals(0, $image->call_count());
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertFalse($DB->record_exists_select(
            'label',
            'course = :c AND ' . $DB->sql_like('intro', ':img'),
            ['c' => $job->courseid, 'img' => '%<img%']
        ));
    }

    /**
     * A text-generation failure is tolerated and logged; the course completes.
     *
     * @return void
     */
    public function test_text_failure_is_tolerated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $job = $this->approved_job();

        $failtext = new stub_text_client([new text_result(success: false, provider: 'p', error: 'boom')]);
        $this->assertTrue((new materializer($failtext, new stub_image_client(false)))->materialize($job));

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_COMPLETE, $job->status);
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'materialize', 'outcome' => 'failure']
        ));
    }

    /**
     * Insert an approved job with a two-section blueprint (section 0 flagged
     * for an image).
     *
     * @return \stdClass The job.
     */
    private function approved_job(): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'automatic',
            'status' => job_manager::STATUS_APPROVED,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $blueprint = blueprint::from_array([
            'title' => 'Test Course',
            'description' => 'A description.',
            'sections' => [
                ['title' => 'Intro', 'objectives' => ['Understand'], 'summary' => 's1',
                 'image' => ['generate' => true, 'prompthint' => 'a diagram']],
                ['title' => 'Advanced', 'objectives' => ['Apply'], 'summary' => 's2',
                 'image' => ['generate' => false]],
            ],
        ]);
        blueprint_store::save_new_version($job, $blueprint, 2);
        return $job;
    }

    /**
     * A stub text client that returns reading HTML for every call.
     *
     * @return stub_text_client
     */
    private function text(): stub_text_client {
        return new stub_text_client([
            new text_result(
                success: true,
                content: '<p>Reading content for the section.</p>',
                model: 'stub-model',
                provider: 'stubprovider',
                prompttokens: 9,
                completiontokens: 40,
            ),
        ]);
    }
}
