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
 * Test double for the image client — returns a canned draft image file.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Returns a small real PNG in a draft area on each call (or a configured
 * failure), so materialization and inline embedding can be tested with no
 * live image model.
 */
class stub_image_client implements image_client {
    /** @var bool Whether calls succeed. */
    private bool $succeed;

    /** @var int Number of generate_image() calls made. */
    private int $calls = 0;

    /**
     * Configure the stub.
     *
     * @param bool $succeed Whether generated calls return an image (false to simulate failure).
     */
    public function __construct(bool $succeed = true) {
        $this->succeed = $succeed;
    }

    /**
     * Return a canned image result, creating a real draft PNG when succeeding.
     *
     * @param string $prompt The prompt.
     * @param \context $context The context.
     * @param int $userid The user id.
     * @return image_result
     */
    public function generate_image(string $prompt, \context $context, int $userid): image_result {
        $this->calls++;
        if (!$this->succeed) {
            return new image_result(success: false, provider: 'stubimageprovider', error: 'stub failure');
        }
        $fs = get_file_storage();
        $draftfile = $fs->create_file_from_string([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => 'stub' . $this->calls . '.png',
        ], $this->png_bytes());
        return new image_result(
            success: true,
            draftfile: $draftfile,
            model: 'stub-image-model',
            provider: 'stubimageprovider',
        );
    }

    /**
     * Number of calls made.
     *
     * @return int
     */
    public function call_count(): int {
        return $this->calls;
    }

    /**
     * A minimal 1x1 PNG.
     *
     * @return string
     */
    private function png_bytes(): string {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
        );
    }
}
