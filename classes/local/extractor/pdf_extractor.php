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
 * PDF extractor using the vendored smalot/pdfparser library.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

use local_coursegen\local\corpus;

/**
 * Extracts text from PDFs page by page. PDFs carry no reliable heading
 * structure, so output is paragraph blocks (split on blank lines); page order
 * is preserved. See thirdpartylibs.xml for the bundled library.
 */
class pdf_extractor implements file_extractor {
    /**
     * Extract a corpus from a .pdf stored file.
     *
     * @param \stored_file $file The source file.
     * @return corpus
     */
    public function extract(\stored_file $file): corpus {
        require_once(__DIR__ . '/../../../vendor/autoload.php');

        if (!class_exists('\Smalot\PdfParser\Parser')) {
            throw new extraction_exception('pdf parser library unavailable');
        }

        $corpus = new corpus();
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($file->get_content());
            $pages = $pdf->getPages();
        } catch (\Exception $e) {
            // The smalot/pdfparser library signals parse problems with generic
            // exceptions; wrap only this call site so coding errors still surface.
            throw new extraction_exception('pdf parse error');
        }

        foreach ($pages as $page) {
            $text = str_replace(["\r\n", "\r"], "\n", $page->getText());
            foreach (preg_split('/\n\s*\n/', $text) as $chunk) {
                $chunk = trim(preg_replace('/[ \t]*\n[ \t]*/', ' ', $chunk));
                if ($chunk !== '') {
                    $corpus->add_paragraph($chunk);
                }
            }
        }

        return $corpus;
    }
}
