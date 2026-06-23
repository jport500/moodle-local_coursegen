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
 * Seam over the AI Providers image-generation action.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * A narrow interface around a single `image`-tier image-generation call.
 * The real implementation routes through core_ai's generate_image action;
 * tests inject a stub so no live model is contacted (mirrors {@see text_client}).
 */
interface image_client {
    /**
     * Generate an image for a prompt.
     *
     * @param string $prompt The image prompt.
     * @param \context $context The context the request is made in.
     * @param int $userid The user the request is attributed to.
     * @param string $aspectratio One of 'square' | 'landscape' | 'portrait' (D36);
     *        defaults to 'square' so existing callers are unchanged.
     * @return image_result The outcome, including the draft image file.
     */
    public function generate_image(
        string $prompt,
        \context $context,
        int $userid,
        string $aspectratio = 'square'
    ): image_result;
}
