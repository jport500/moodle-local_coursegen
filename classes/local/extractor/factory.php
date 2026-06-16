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
 * Maps a source type to its extractor and classifies uploaded files.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

/**
 * Factory for file extractors and the single place that knows which file
 * extensions map to which source type.
 */
class factory {
    /** @var string PDF source type. */
    public const TYPE_PDF = 'pdf';

    /** @var string DOCX source type. */
    public const TYPE_DOCX = 'docx';

    /** @var string PPTX source type. */
    public const TYPE_PPTX = 'pptx';

    /** @var string Plain text / Markdown source type. */
    public const TYPE_TEXT = 'text';

    /** @var string Topic-only prompt (no file; trivial corpus). */
    public const TYPE_TOPIC = 'topic';

    /** @var array<string,string> File extension => source type. */
    private const EXTENSION_MAP = [
        'pdf' => self::TYPE_PDF,
        'docx' => self::TYPE_DOCX,
        'pptx' => self::TYPE_PPTX,
        'txt' => self::TYPE_TEXT,
        'md' => self::TYPE_TEXT,
        'markdown' => self::TYPE_TEXT,
    ];

    /**
     * Classify an uploaded file by its extension.
     *
     * @param string $filename The original filename.
     * @return string|null The source type, or null if the type is unsupported.
     */
    public static function type_for_filename(string $filename): ?string {
        $ext = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::EXTENSION_MAP[$ext] ?? null;
    }

    /**
     * The file extensions accepted for upload (for the form filemanager).
     *
     * @return string[] Extensions with leading dots, e.g. ['.pdf', '.docx', ...].
     */
    public static function accepted_extensions(): array {
        return array_map(static fn(string $ext): string => '.' . $ext, array_keys(self::EXTENSION_MAP));
    }

    /**
     * Build the extractor for a file-backed source type.
     *
     * @param string $type One of the file-backed TYPE_* constants.
     * @return file_extractor
     * @throws \coding_exception for unknown or non-file types (e.g. topic).
     */
    public static function make(string $type): file_extractor {
        switch ($type) {
            case self::TYPE_PDF:
                return new pdf_extractor();
            case self::TYPE_DOCX:
                return new docx_extractor();
            case self::TYPE_PPTX:
                return new pptx_extractor();
            case self::TYPE_TEXT:
                return new text_extractor();
            default:
                throw new \coding_exception('No file extractor for source type: ' . $type);
        }
    }
}
