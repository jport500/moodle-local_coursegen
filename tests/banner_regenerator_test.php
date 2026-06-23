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
 * Tests for the intro-banner regenerator (D36).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\stub_image_client;
use local_coursegen\local\ai\stub_quiz_client;
use local_coursegen\local\ai\stub_text_client;
use local_coursegen\local\ai\text_result;
use local_coursegen\local\banner_regenerator;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\materializer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_text_client.php');
require_once(__DIR__ . '/fixtures/stub_image_client.php');
require_once(__DIR__ . '/fixtures/stub_quiz_client.php');

/**
 * Regenerating the banner replaces the standalone file under a new filename (no
 * HTML, no anchor); a failed regenerate leaves the existing banner intact.
 */
#[CoversClass(\local_coursegen\local\banner_regenerator::class)]
final class banner_regenerator_test extends \advanced_testcase {
    /**
     * Regenerate writes a new banner (new filename + contenthash), the old file is
     * gone (no orphan), and an image is logged.
     *
     * @return void
     */
    public function test_regenerate_replaces_banner(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$job, $courseid] = $this->built_course_with_banner();

        [$namebefore, $hashbefore, $countbefore] = $this->banner_state($courseid);
        $this->assertNotNull($namebefore);
        $this->assertSame(1, $countbefore);

        $this->assertTrue((new banner_regenerator($this->image('regenerated')))->regenerate($job, 2));

        [$nameafter, $hashafter, $countafter] = $this->banner_state($courseid);
        $this->assertNotSame($namebefore, $nameafter, 'the filename should change (cache-bust)');
        $this->assertNotSame($hashbefore, $hashafter, 'the image content should change');
        $this->assertSame(1, $countafter, 'the old banner file must be gone (no orphan)');
        $this->assertTrue($DB->record_exists_select(
            'coursegen_log',
            "jobid = :j AND tier = 'image' AND outcome = 'success' AND imagecount = 1 AND "
                . $DB->sql_like('detail', ':d'),
            ['j' => $job->id, 'd' => 'regenerate intro header banner']
        ));
    }

    /**
     * A failed regeneration leaves the existing banner untouched and logs failure.
     *
     * @return void
     */
    public function test_failed_regenerate_keeps_banner(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$job, $courseid] = $this->built_course_with_banner();
        [$namebefore, $hashbefore] = $this->banner_state($courseid);

        $this->assertFalse((new banner_regenerator(new stub_image_client(false)))->regenerate($job, 2));

        [$nameafter, $hashafter] = $this->banner_state($courseid);
        $this->assertSame($namebefore, $nameafter);
        $this->assertSame($hashbefore, $hashafter, 'the banner must be untouched on failure');
        $this->assertTrue($DB->record_exists_select(
            'coursegen_log',
            "jobid = :j AND outcome = 'failure' AND " . $DB->sql_like('detail', ':d'),
            ['j' => $job->id, 'd' => 'regenerate intro header banner%']
        ));
    }

    /**
     * No banner present (e.g. opt-in was off) → not actionable.
     *
     * @return void
     */
    public function test_no_banner_no_op(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // A built course WITHOUT the banner opt-in.
        $job = $this->job_for_course(false);
        $this->assertFalse(materializer::section0_has_banner((int) $job->courseid));
        $this->assertFalse((new banner_regenerator($this->image('x')))->regenerate($job, 2));
    }

    /**
     * Build a course with the banner opt-in on, so a banner file is present.
     *
     * @return array{0:\stdClass,1:int}
     */
    private function built_course_with_banner(): array {
        $job = $this->job_for_course(true);
        return [$job, (int) $job->courseid];
    }

    /**
     * Materialize a one-section course, optionally with the banner opt-in.
     *
     * @param bool $banner Whether to opt into the intro banner.
     * @return \stdClass The completed job.
     */
    private function job_for_course(bool $banner): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2, 'contextid' => $context->id, 'courseid' => null, 'mode' => 'automatic',
            'headerbanner' => $banner ? 1 : 0, 'status' => 'approved',
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $bp = blueprint::from_array(['title' => 'Banner Course', 'description' => 'd', 'sections' => [
            ['title' => 'One', 'summary' => 's', 'objectives' => ['o'], 'assessment' => ['type' => 'none']],
        ]]);
        blueprint_store::save_new_version($job, $bp, 2);
        $text = new stub_text_client([new text_result(
            success: true,
            content: '<p>r</p>',
            model: 'm',
            provider: 'p',
            prompttokens: 1,
            completiontokens: 1
        )]);
        (new materializer($text, new stub_image_client(true), new stub_quiz_client(true)))->materialize($job);
        return $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
    }

    /**
     * The section-0 banner file's name, contenthash, and count.
     *
     * @param int $courseid The course id.
     * @return array{0:?string,1:?string,2:int}
     */
    private function banner_state(int $courseid): array {
        global $DB;
        $section0id = (int) $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => 0]);
        $files = get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id,
            'format_pathway',
            'sectionimage',
            $section0id,
            'id',
            false
        );
        $f = $files ? reset($files) : null;
        return [$f ? $f->get_filename() : null, $f ? $f->get_contenthash() : null, count($files)];
    }

    /**
     * A stub image client returning a draft file with the given bytes.
     *
     * @param string $marker A marker to make the content distinct.
     * @return \local_coursegen\local\ai\image_client
     */
    private function image(string $marker): \local_coursegen\local\ai\image_client {
        return new class ($marker) implements \local_coursegen\local\ai\image_client {
            /**
             * Construct the stub.
             *
             * @param string $marker The content marker.
             */
            public function __construct(
                /** @var string The content marker. */
                private string $marker,
            ) {
            }

            #[\Override]
            public function generate_image(
                string $prompt,
                \context $context,
                int $userid,
                string $aspectratio = 'square'
            ): \local_coursegen\local\ai\image_result {
                $draft = get_file_storage()->create_file_from_string([
                    'contextid' => \context_user::instance($userid)->id,
                    'component' => 'user', 'filearea' => 'draft',
                    'itemid' => file_get_unused_draft_itemid(),
                    'filepath' => '/', 'filename' => 'banner.png',
                ], "\x89PNG\r\n\x1a\n" . $this->marker);
                return new \local_coursegen\local\ai\image_result(
                    success: true,
                    draftfile: $draft,
                    model: 'm',
                    provider: 'p'
                );
            }
        };
    }
}
