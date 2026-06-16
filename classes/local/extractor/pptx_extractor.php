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
 * PPTX extractor using ZipArchive (no third-party library).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\extractor;

use local_coursegen\local\corpus;

/**
 * Reads ppt/slides/slideN.xml in slide order. Each slide's title placeholder
 * becomes a level-2 heading; other shape paragraphs become paragraph blocks.
 */
class pptx_extractor implements file_extractor {
    /** @var string DrawingML namespace. */
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /** @var string PresentationML namespace. */
    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    /**
     * Extract a corpus from a .pptx stored file.
     *
     * @param \stored_file $file The source file.
     * @return corpus
     */
    public function extract(\stored_file $file): corpus {
        $corpus = new corpus();
        $slides = zip_member_reader::read_matching($file, '#^ppt/slides/slide\d+\.xml$#');
        foreach ($slides as $slidexml) {
            $this->parse_slide($slidexml, $corpus);
        }
        return $corpus;
    }

    /**
     * Parse one slide's XML, appending its title and body blocks.
     *
     * @param string $xml The slide XML.
     * @param corpus $corpus The corpus to append to.
     * @return void
     */
    private function parse_slide(string $xml, corpus $corpus): void {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            // Skip an unreadable slide rather than failing the whole deck.
            return;
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', self::NS_A);
        $xpath->registerNamespace('p', self::NS_P);

        foreach ($xpath->query('//p:sp') as $shape) {
            $istitle = $xpath->query(".//p:nvSpPr/p:nvPr/p:ph[@type='title' or @type='ctrTitle']", $shape)->length > 0;
            foreach ($xpath->query('.//a:p', $shape) as $para) {
                $text = '';
                foreach ($xpath->query('.//a:t', $para) as $textnode) {
                    $text .= $textnode->textContent;
                }
                if (trim($text) === '') {
                    continue;
                }
                if ($istitle) {
                    $corpus->add_heading($text, 2);
                } else {
                    $corpus->add_paragraph($text);
                }
            }
        }
    }
}
