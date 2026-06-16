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
 * DOCX extractor using ZipArchive (no third-party library).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

use local_coursegen\local\corpus;

/**
 * Reads word/document.xml from the .docx (OOXML) package. Each <w:p> is one
 * block; paragraphs whose style is "Heading N" become heading blocks at level N.
 */
class docx_extractor implements file_extractor {
    /**
     * Extract a corpus from a .docx stored file.
     *
     * @param \stored_file $file The source file.
     * @return corpus
     */
    public function extract(\stored_file $file): corpus {
        $xml = zip_member_reader::read($file, 'word/document.xml');
        return $this->parse_document_xml($xml);
    }

    /**
     * Parse the body of word/document.xml into ordered blocks.
     *
     * @param string $xml The document.xml contents.
     * @return corpus
     */
    private function parse_document_xml(string $xml): corpus {
        $corpus = new corpus();

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new extraction_exception('invalid document.xml');
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($xpath->query('//w:body/w:p') as $para) {
            // Concatenate all text runs in this paragraph.
            $text = '';
            foreach ($xpath->query('.//w:t', $para) as $textnode) {
                $text .= $textnode->textContent;
            }
            if (trim($text) === '') {
                continue;
            }
            $level = $this->heading_level($xpath, $para);
            if ($level !== null) {
                $corpus->add_heading($text, $level);
            } else {
                $corpus->add_paragraph($text);
            }
        }

        return $corpus;
    }

    /**
     * Determine the heading level of a paragraph from its style, if any.
     *
     * @param \DOMXPath $xpath The bound xpath instance.
     * @param \DOMNode $para The <w:p> node.
     * @return int|null Heading level 1–6, or null when not a heading.
     */
    private function heading_level(\DOMXPath $xpath, \DOMNode $para): ?int {
        $stylenodes = $xpath->query('.//w:pPr/w:pStyle/@w:val', $para);
        if ($stylenodes->length === 0) {
            return null;
        }
        $style = strtolower((string) $stylenodes->item(0)->nodeValue);
        // Word styles: "Heading1".."Heading6", or "Heading 1" / "heading1".
        if (preg_match('/heading\s*([1-6])/', $style, $m)) {
            return (int) $m[1];
        }
        if ($style === 'title') {
            return 1;
        }
        return null;
    }
}
