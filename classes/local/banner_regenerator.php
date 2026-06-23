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
 * Regenerates a built course's intro header banner (D36).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

use local_coursegen\local\ai\image_client;

/**
 * Reruns ONLY the intro-banner generation for a built course, reusing the course
 * title (D36). Simpler than the section-image regenerate (D33/D34): the banner is
 * a standalone file in format_pathway's section-0 'sectionimage' filearea, with no
 * label.intro and no embedded reference — so there is NO HTML to edit, no anchor,
 * no single-occurrence guard. It is just generate → write under a fresh filename
 * (a new pluginfile URL busts the browser cache) → rebuild the course cache.
 */
class banner_regenerator {
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
     * Regenerate the course's intro banner in place. Returns true only when a new
     * banner was written. On any refusal/failure the existing banner is left
     * exactly as it is.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param int $userid The requesting user.
     * @return bool
     */
    public function regenerate(\stdClass $job, int $userid): bool {
        global $DB, $CFG;

        // Only act on a built course that actually has a banner to replace.
        if (!materializer::section0_has_banner((int) $job->courseid)) {
            return false;
        }

        // Respect the image sub-cap — refuse cleanly, leaving the banner untouched.
        if (spend_governor::image_remaining() <= 0) {
            audit_log::record(
                (int) $job->id,
                $userid,
                self::STAGE,
                audit_log::FAILURE,
                'regenerate intro header banner refused: image cap reached',
                ['tier' => self::TIER]
            );
            return false;
        }

        $blueprint = blueprint_store::load_current($job->id);
        $title = $blueprint?->get_title() ?? '';
        $course = get_course((int) $job->courseid);
        $coursecontext = \context_course::instance((int) $job->courseid);

        $result = $this->imageclient->generate_image(
            materializer::banner_prompt($title),
            $coursecontext,
            $userid,
            'landscape'
        );
        if (!$result->success || $result->draftfile === null) {
            audit_log::record(
                (int) $job->id,
                $userid,
                self::STAGE,
                audit_log::FAILURE,
                'regenerate intro header banner failed: ' . $result->error,
                ['tier' => self::TIER, 'actionname' => 'generate_image', 'provider' => $result->provider]
            );
            return false;
        }

        try {
            materializer::write_section0_banner($course, $coursecontext, $result->draftfile);
        } catch (\Throwable $e) {
            // Leave the existing banner as-is on a write failure.
            audit_log::record(
                (int) $job->id,
                $userid,
                self::STAGE,
                audit_log::FAILURE,
                'regenerate intro header banner not set: ' . $e->getMessage(),
                ['tier' => self::TIER]
            );
            return false;
        }

        require_once($CFG->dirroot . '/lib/modinfolib.php');
        rebuild_course_cache((int) $job->courseid, true);

        audit_log::record(
            (int) $job->id,
            $userid,
            self::STAGE,
            audit_log::SUCCESS,
            'regenerate intro header banner',
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
}
