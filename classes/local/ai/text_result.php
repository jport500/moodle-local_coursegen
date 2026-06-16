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
 * Result of a text-generation call.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Immutable outcome of a {@see text_client::generate()} call, carrying the
 * generated content plus the resolved provider/model/token counts needed for
 * the §10.2 audit log.
 */
class text_result {
    /**
     * Construct an immutable text-generation result.
     *
     * @param bool $success Whether generation succeeded.
     * @param string $content The generated text (empty on failure).
     * @param string|null $model The resolved model, where reported.
     * @param string|null $provider The resolved provider name, where known.
     * @param int|null $prompttokens Prompt tokens consumed, where reported.
     * @param int|null $completiontokens Completion tokens produced, where reported.
     * @param string $error A non-sensitive error description on failure.
     */
    public function __construct(
        /** @var bool Whether generation succeeded. */
        public readonly bool $success,
        /** @var string The generated text. */
        public readonly string $content = '',
        /** @var string|null The resolved model. */
        public readonly ?string $model = null,
        /** @var string|null The resolved provider name. */
        public readonly ?string $provider = null,
        /** @var int|null Prompt tokens consumed. */
        public readonly ?int $prompttokens = null,
        /** @var int|null Completion tokens produced. */
        public readonly ?int $completiontokens = null,
        /** @var string Non-sensitive error description. */
        public readonly string $error = '',
    ) {
    }
}
