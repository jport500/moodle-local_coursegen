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
 * Contract for source-file text/structure extractors.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

use local_coursegen\local\corpus;

/**
 * Implemented by each per-format extractor (PDF, DOCX, PPTX, text/markdown).
 */
interface file_extractor {
    /**
     * Extract a normalized corpus from a stored source file.
     *
     * @param \stored_file $file The permanent source file.
     * @return corpus The ordered, structure-aware corpus.
     * @throws \local_coursegen\local\extractor\extraction_exception on unreadable/corrupt input.
     */
    public function extract(\stored_file $file): corpus;
}
