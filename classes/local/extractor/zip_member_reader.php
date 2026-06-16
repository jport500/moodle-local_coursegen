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
 * Helper for reading members out of an OOXML (zip) source file.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

/**
 * Thin wrapper over ZipArchive (a core PHP extension) for the OOXML formats
 * (.docx/.pptx), so the DOCX/PPTX extractors need no third-party library.
 */
class zip_member_reader {
    /**
     * Read a single named member from the zip-based file.
     *
     * @param \stored_file $file The source file.
     * @param string $name The member path, e.g. 'word/document.xml'.
     * @return string The member contents.
     * @throws extraction_exception when the archive or member cannot be read.
     */
    public static function read(\stored_file $file, string $name): string {
        $zip = self::open($file, $temppath);
        try {
            $contents = $zip->getFromName($name);
            if ($contents === false) {
                throw new extraction_exception("missing zip member: {$name}");
            }
            return $contents;
        } finally {
            $zip->close();
            @unlink($temppath);
        }
    }

    /**
     * Read every member whose name matches a regex, ordered by the first
     * integer in the name (so slide2 precedes slide10).
     *
     * @param \stored_file $file The source file.
     * @param string $regex A PCRE matched against each member name.
     * @return string[] Member contents, in natural order.
     * @throws extraction_exception when the archive cannot be read.
     */
    public static function read_matching(\stored_file $file, string $regex): array {
        $zip = self::open($file, $temppath);
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match($regex, $name)) {
                    $names[] = $name;
                }
            }
            usort($names, static function (string $a, string $b): int {
                $na = (int) (preg_match('/(\d+)/', $a, $m) ? $m[1] : 0);
                $nb = (int) (preg_match('/(\d+)/', $b, $m) ? $m[1] : 0);
                return $na <=> $nb;
            });
            $out = [];
            foreach ($names as $name) {
                $contents = $zip->getFromName($name);
                if ($contents !== false) {
                    $out[] = $contents;
                }
            }
            return $out;
        } finally {
            $zip->close();
            @unlink($temppath);
        }
    }

    /**
     * Copy the stored file to a temp path and open it as a zip archive.
     *
     * @param \stored_file $file The source file.
     * @param string|null $temppath Set by reference to the temp path to clean up.
     * @return \ZipArchive
     * @throws extraction_exception when the archive cannot be opened.
     */
    private static function open(\stored_file $file, ?string &$temppath): \ZipArchive {
        $temppath = $file->copy_content_to_temp('local_coursegen', 'src');
        $zip = new \ZipArchive();
        if ($zip->open($temppath) !== true) {
            @unlink($temppath);
            throw new extraction_exception('not a readable archive');
        }
        return $zip;
    }
}
