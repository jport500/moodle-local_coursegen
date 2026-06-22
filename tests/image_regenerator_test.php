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
 * Tests for regenerate-image-only (D33).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\image_result;
use local_coursegen\local\ai\stub_image_client;
use local_coursegen\local\ai\stub_quiz_client;
use local_coursegen\local\ai\stub_text_client;
use local_coursegen\local\ai\text_result;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\image_regenerator;
use local_coursegen\local\materializer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_text_client.php');
require_once(__DIR__ . '/fixtures/stub_image_client.php');
require_once(__DIR__ . '/fixtures/stub_quiz_client.php');

/**
 * Regenerating one section's image replaces the file in place while the reading
 * prose and the knowledge-check token in the label survive byte-for-byte.
 */
#[CoversClass(\local_coursegen\local\image_regenerator::class)]
final class image_regenerator_test extends \advanced_testcase {
    /**
     * The critical case: regenerating the image replaces the file content but
     * leaves the reading prose AND the {knowledgecheck} token in label.intro
     * exactly unchanged; the other section's image is untouched.
     *
     * @return void
     */
    public function test_regenerate_keeps_reading_and_token(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        filter_set_global_state('knowledgecheck', TEXTFILTER_ON);
        [$job, $courseid] = $this->built_course();

        // Capture section 0's reading label intro (reading + image + KC token) and
        // its image file content before regeneration.
        [$introbefore, $filebefore] = $this->label_intro_and_image($courseid, 0);
        $this->assertStringContainsString('{knowledgecheck id=', $introbefore);
        $this->assertStringContainsString('@@PLUGINFILE@@', $introbefore);

        $newbytes = $this->png_bytes('regenerated');
        $regen = new image_regenerator($this->image_returning($newbytes));
        $this->assertTrue($regen->regenerate($job, 0, 2));

        // The label intro is byte-for-byte identical: reading prose and token survive.
        [$introafter, $fileafter] = $this->label_intro_and_image($courseid, 0);
        $this->assertSame($introbefore, $introafter, 'label.intro must be unchanged');
        // The image FILE content changed.
        $this->assertNotSame($filebefore, $fileafter, 'the image file should be replaced');
        $this->assertSame($newbytes, $fileafter);

        // An image-regenerate success row was logged with imagecount 1.
        $this->assertTrue($DB->record_exists_select(
            'coursegen_log',
            "jobid = :j AND tier = 'image' AND outcome = 'success' AND imagecount = 1 AND "
                . $DB->sql_like('detail', ':d'),
            ['j' => $job->id, 'd' => 'regenerate image (section 0)%']
        ));

        // The OTHER section's image is untouched.
        [, $other] = $this->label_intro_and_image($courseid, 1);
        $this->assertNotSame($newbytes, $other, 'section 1 image must be untouched');
    }

    /**
     * A failed generation leaves the existing image and label exactly as-is and
     * surfaces the failure (returns false, logs failure).
     *
     * @return void
     */
    public function test_failed_generation_keeps_everything(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$job, $courseid] = $this->built_course();
        [$introbefore, $filebefore] = $this->label_intro_and_image($courseid, 0);

        $fail = new stub_image_client(false);
        $this->assertFalse((new image_regenerator($fail))->regenerate($job, 0, 2));

        [$introafter, $fileafter] = $this->label_intro_and_image($courseid, 0);
        $this->assertSame($introbefore, $introafter);
        $this->assertSame($filebefore, $fileafter, 'the image must be untouched on failure');
        $this->assertTrue($DB->record_exists_select(
            'coursegen_log',
            "jobid = :j AND tier = 'image' AND outcome = 'failure'",
            ['j' => $job->id]
        ));
    }

    /**
     * Over the image sub-cap: regeneration refuses cleanly, image untouched.
     *
     * @return void
     */
    public function test_over_image_cap_refuses(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$job, $courseid] = $this->built_course();
        [, $filebefore] = $this->label_intro_and_image($courseid, 0);

        // Exhaust the image sub-cap.
        set_config('cap_image_count', 1, 'local_coursegen');
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id, 'stage' => 'materialize', 'imagecount' => 5,
            'outcome' => 'success', 'timecreated' => time(),
        ]);

        $newbytes = $this->png_bytes('should-not-apply');
        $this->assertFalse((new image_regenerator($this->image_returning($newbytes)))->regenerate($job, 0, 2));
        [, $fileafter] = $this->label_intro_and_image($courseid, 0);
        $this->assertSame($filebefore, $fileafter);
    }

    /**
     * A section with no image (not image-flagged) is not actionable.
     *
     * @return void
     */
    public function test_section_without_image_no_ops(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$job] = $this->built_course();

        // Section 2 has no image in the built course.
        $this->assertFalse(image_regenerator::section_has_image($job, 2));
        $this->assertFalse((new image_regenerator($this->image_returning($this->png_bytes('x'))))
            ->regenerate($job, 2, 2));
        // Section 0 does have an image.
        $this->assertTrue(image_regenerator::section_has_image($job, 0));
    }

    /**
     * Materialize a 3-section course: section 0 has an image + a knowledge check,
     * section 1 has an image, section 2 has neither.
     *
     * @return array{0:\stdClass,1:int}
     */
    private function built_course(): array {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2, 'contextid' => $context->id, 'courseid' => null, 'mode' => 'automatic',
            'status' => 'approved', 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 2,
        ]);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $bp = blueprint::from_array(['title' => 'C', 'description' => 'd', 'sections' => [
            ['title' => 'WithImageAndKc', 'summary' => 's', 'objectives' => ['o'],
             'image' => ['generate' => true, 'prompthint' => 'a leaf'],
             'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 2]],
            ['title' => 'WithImage', 'summary' => 's', 'objectives' => ['o'],
             'image' => ['generate' => true, 'prompthint' => 'a river'],
             'assessment' => ['type' => 'none']],
            ['title' => 'Plain', 'summary' => 's', 'objectives' => ['o'],
             'image' => ['generate' => false], 'assessment' => ['type' => 'none']],
        ]]);
        blueprint_store::save_new_version($job, $bp, 2);

        $text = new stub_text_client([new text_result(
            success: true,
            content: '<p>Original reading prose for the section.</p>',
            model: 'm',
            provider: 'p',
            prompttokens: 1,
            completiontokens: 1
        )]);
        $this->assertTrue((new materializer($text, new stub_image_client(true), new stub_quiz_client(true)))
            ->materialize($job));
        return [$DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST),
            (int) $DB->get_field('coursegen_job', 'courseid', ['id' => $jobid])];
    }

    /**
     * The reading label's intro HTML and its image file content for a blueprint
     * section index (course section index+1).
     *
     * @param int $courseid The course id.
     * @param int $sectionindex The 0-based blueprint section index.
     * @return array{0:string,1:string} [intro html, image bytes]
     */
    private function label_intro_and_image(int $courseid, int $sectionindex): array {
        global $DB;
        $modinfo = get_fast_modinfo($courseid);
        $labelcm = null;
        foreach ($modinfo->get_sections()[$sectionindex + 1] ?? [] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->modname === 'label') {
                $labelcm = $cm;
                break;
            }
        }
        $this->assertNotNull($labelcm);
        $intro = $DB->get_field('label', 'intro', ['id' => $labelcm->instance], MUST_EXIST);
        $files = get_file_storage()->get_area_files(
            $labelcm->context->id,
            'mod_label',
            'intro',
            0,
            'filename',
            false
        );
        $bytes = $files ? reset($files)->get_content() : '';
        return [$intro, $bytes];
    }

    /**
     * A stub image client returning a draft file with the given bytes.
     *
     * @param string $bytes The PNG bytes to return.
     * @return \local_coursegen\local\ai\image_client
     */
    private function image_returning(string $bytes): \local_coursegen\local\ai\image_client {
        return new class ($bytes) implements \local_coursegen\local\ai\image_client {
            /**
             * Construct the stub with the bytes to return.
             *
             * @param string $bytes The PNG bytes to return.
             */
            public function __construct(
                /** @var string The PNG bytes to return. */
                private string $bytes,
            ) {
            }

            #[\Override]
            public function generate_image(string $prompt, \context $context, int $userid): image_result {
                $draft = get_file_storage()->create_file_from_string([
                    'contextid' => \context_user::instance($userid)->id,
                    'component' => 'user', 'filearea' => 'draft',
                    'itemid' => file_get_unused_draft_itemid(),
                    'filepath' => '/', 'filename' => 'regen.png',
                ], $this->bytes);
                return new image_result(
                    success: true,
                    draftfile: $draft,
                    model: 'stub-image-model',
                    provider: 'stubimageprovider'
                );
            }
        };
    }

    /**
     * Distinct PNG-ish bytes keyed by a marker (content equality is what matters).
     *
     * @param string $marker A marker to make the content distinct.
     * @return string
     */
    private function png_bytes(string $marker): string {
        return "\x89PNG\r\n\x1a\n" . $marker;
    }
}
