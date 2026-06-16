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
 * Result of an image-generation call.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Immutable outcome of an {@see image_client::generate_image()} call. The image
 * is returned as a draft stored_file (as core_ai's generate_image response
 * provides it), ready to embed into a module's file area.
 */
class image_result {
    /**
     * Construct an immutable image-generation result.
     *
     * @param bool $success Whether generation succeeded.
     * @param \stored_file|null $draftfile The generated image in a draft area.
     * @param string|null $model The resolved model, where reported.
     * @param string|null $provider The resolved provider name, where known.
     * @param string $error A non-sensitive error description on failure.
     */
    public function __construct(
        /** @var bool Whether generation succeeded. */
        public readonly bool $success,
        /** @var \stored_file|null The generated image in a draft area. */
        public readonly ?\stored_file $draftfile = null,
        /** @var string|null The resolved model. */
        public readonly ?string $model = null,
        /** @var string|null The resolved provider name. */
        public readonly ?string $provider = null,
        /** @var string Non-sensitive error description. */
        public readonly string $error = '',
    ) {
    }
}
