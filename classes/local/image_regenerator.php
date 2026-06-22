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
 * Regenerates a single section's image on a built course (D33).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

use local_coursegen\local\ai\image_client;

/**
 * Reruns ONLY image generation for one section of a materialized course, reusing
 * the section's stored hint (D33). The image lives as a file in the section's
 * reading-label intro filearea, referenced by @@PLUGINFILE@@/<filename> in the
 * label HTML beside the reading prose and any {knowledgecheck} token. The new
 * image is written under the SAME filename, so the label HTML is never touched
 * and the reading prose + token survive byte-for-byte. The reading is NOT
 * recoverable separately (it exists only in the label), so this is the safe path.
 */
class image_regenerator {
    /** @var string Audit stage (a materialize-domain image op). */
    private const STAGE = 'materialize';

    /** @var string Capability tier. */
    private const TIER = 'image';

    /**
     * Construct the regenerator.
     *
     * @param image_client $imageclient The image client (injectable for tests).
     */
    public function __construct(
        /** @var image_client The image client. */
        private image_client $imageclient,
    ) {
    }

    /**
     * Whether a built section currently has an image file to replace — used both
     * to offer the action and to validate it. False when the job has no course,
     * the section isn't image-flagged, or no image file is present (e.g. a
     * flagged image whose generation failed at materialize — out of scope for v1).
     *
     * @param \stdClass $job The job.
     * @param int $sectionindex The 0-based blueprint section index.
     * @return bool
     */
    public static function section_has_image(\stdClass $job, int $sectionindex): bool {
        return self::locate_image_file($job, $sectionindex) !== null;
    }

    /**
     * Regenerate section $sectionindex's image in place. Returns true only when a
     * new image was written. On any refusal/failure the existing image and the
     * label are left exactly as they are.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param int $sectionindex The 0-based blueprint section index.
     * @param int $userid The requesting user.
     * @return bool
     */
    public function regenerate(\stdClass $job, int $sectionindex, int $userid): bool {
        global $DB;

        $existing = self::locate_image_file($job, $sectionindex);
        if ($existing === null) {
            return false;
        }
        $context = \context::instance_by_id($job->contextid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        // Respect the image sub-cap — refuse cleanly, leaving the image untouched.
        if (spend_governor::image_remaining() <= 0) {
            audit_log::record(
                (int) $job->id,
                $userid,
                self::STAGE,
                audit_log::FAILURE,
                "regenerate image (section {$sectionindex}) refused: image cap reached",
                ['tier' => self::TIER]
            );
            return false;
        }

        $blueprint = blueprint_store::load_current($job->id);
        $section = $blueprint?->get_sections()[$sectionindex] ?? null;
        $hint = ($section['image']['prompthint'] ?? '') !== ''
            ? $section['image']['prompthint']
            : ($section['title'] ?? '');
        $coursecontext = \context_course::instance((int) $job->courseid);

        $result = $this->imageclient->generate_image(
            materializer::section_image_prompt($hint),
            $coursecontext,
            $userid
        );
        if (!$result->success || $result->draftfile === null) {
            // Leave the existing image and label exactly as-is.
            audit_log::record(
                (int) $job->id,
                $userid,
                self::STAGE,
                audit_log::FAILURE,
                "regenerate image (section {$sectionindex}) failed: " . $result->error,
                ['tier' => self::TIER, 'actionname' => 'generate_image', 'provider' => $result->provider]
            );
            return false;
        }

        // Replace the file in place under the SAME filename so the label HTML's
        // @@PLUGINFILE@@/<filename> reference still resolves — the reading prose and
        // the {knowledgecheck} token are never touched.
        $filerecord = [
            'contextid' => $existing->get_contextid(),
            'component' => $existing->get_component(),
            'filearea' => $existing->get_filearea(),
            'itemid' => $existing->get_itemid(),
            'filepath' => $existing->get_filepath(),
            'filename' => $existing->get_filename(),
        ];
        $existing->delete();
        get_file_storage()->create_file_from_storedfile($filerecord, $result->draftfile);

        audit_log::record(
            (int) $job->id,
            $userid,
            self::STAGE,
            audit_log::SUCCESS,
            "regenerate image (section {$sectionindex})",
            [
                'tier' => self::TIER,
                'actionname' => 'generate_image',
                'provider' => $result->provider,
                'model' => $result->model,
                'imagecount' => 1,
            ]
        );
        return true;
    }

    /**
     * Locate the single image file in a built section's reading-label intro area,
     * or null if there is no course, the section isn't image-flagged, the section
     * has no reading label, or no image file is present.
     *
     * @param \stdClass $job The job.
     * @param int $sectionindex The 0-based blueprint section index.
     * @return \stored_file|null
     */
    private static function locate_image_file(\stdClass $job, int $sectionindex): ?\stored_file {
        global $DB;
        if (empty($job->courseid) || !$DB->record_exists('course', ['id' => $job->courseid])) {
            return null;
        }
        $blueprint = blueprint_store::load_current($job->id);
        $section = $blueprint?->get_sections()[$sectionindex] ?? null;
        if ($section === null || empty($section['image']['generate'])) {
            return null;
        }

        // Intro is course section 0 (D25), so blueprint section i is course section i+1.
        $modinfo = get_fast_modinfo((int) $job->courseid);
        $cmids = $modinfo->get_sections()[$sectionindex + 1] ?? [];
        $labelcm = null;
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->modname === 'label') {
                $labelcm = $cm;
                break;
            }
        }
        if ($labelcm === null) {
            return null;
        }

        $files = get_file_storage()->get_area_files(
            $labelcm->context->id,
            'mod_label',
            'intro',
            0,
            'filename',
            false
        );
        return $files ? reset($files) : null;
    }
}
