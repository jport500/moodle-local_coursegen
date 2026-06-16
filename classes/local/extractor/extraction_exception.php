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
 * Raised when a source file cannot be read or parsed.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

/**
 * A narrow exception representing an extraction failure for one source.
 *
 * Catching this (rather than \Throwable) keeps fail-handling from masking
 * programmer errors, per the LMS Light fail-open guidance.
 */
class extraction_exception extends \moodle_exception {
    /**
     * Build the extraction exception.
     *
     * @param string $reason Short, non-sensitive reason (becomes the debug detail).
     */
    public function __construct(string $reason = '') {
        parent::__construct('extractionfailed', 'local_coursegen', '', null, $reason);
    }
}
