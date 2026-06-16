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
 * Plain text and Markdown extractor.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

use local_coursegen\local\corpus;

/**
 * Turns text/markdown into a corpus. Markdown ATX headings (`#`..`######`)
 * become heading blocks; blank-line-separated runs become paragraphs.
 */
class text_extractor implements file_extractor {
    /**
     * Extract a corpus from a text/markdown stored file.
     *
     * @param \stored_file $file The source file.
     * @return corpus
     */
    public function extract(\stored_file $file): corpus {
        return $this->extract_string($file->get_content());
    }

    /**
     * Build a corpus from a raw text/markdown string. Shared by the file path
     * and reused by the topic-only path.
     *
     * @param string $text The source text.
     * @return corpus
     */
    public function extract_string(string $text): corpus {
        $corpus = new corpus();
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Split into blank-line-separated chunks, preserving heading lines.
        $lines = explode("\n", $text);
        $buffer = [];

        $flush = function () use (&$buffer, $corpus): void {
            if ($buffer) {
                $corpus->add_paragraph(implode(' ', $buffer));
                $buffer = [];
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $trimmed, $m)) {
                $flush();
                $corpus->add_heading($m[2], \core_text::strlen($m[1]));
                continue;
            }
            $buffer[] = $trimmed;
        }
        $flush();

        return $corpus;
    }
}
