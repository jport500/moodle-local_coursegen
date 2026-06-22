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
 * Tests for the edit blueprint form's section headers and action region.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\form\edit_blueprint_form;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The collapsible section headers carry their titles (Item 1) and the global
 * actions sit in their own region below the sections (Item 2).
 */
#[CoversClass(\local_coursegen\form\edit_blueprint_form::class)]
final class edit_blueprint_form_test extends \advanced_testcase {
    /**
     * Build the form and return its underlying MoodleQuickForm for inspection.
     *
     * @param array $customdata The form customdata.
     * @return \MoodleQuickForm
     */
    private function build(array $customdata): \MoodleQuickForm {
        $form = new edit_blueprint_form('/test', $customdata);
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        return $property->getValue($form);
    }
    /**
     * Each loaded section's header reads "Section N: <title>"; a blank title
     * falls back to a bare "Section N" with no trailing colon.
     *
     * @return void
     */
    public function test_section_headers_carry_titles(): void {
        $this->resetAfterTest();
        $mform = $this->build([
            'sectioncount' => 3,
            'canapprove' => true,
            'sectiontitles' => ['Photosynthesis Basics', '', 'Light Reactions'],
        ]);

        // Titled rows: "Section N: <title>".
        $h0 = $mform->getElement('sectionheader[0]')->_text;
        $this->assertStringContainsString('Photosynthesis Basics', $h0);
        $this->assertStringContainsString('1', $h0);
        $this->assertStringContainsString(':', $h0);

        $h2 = $mform->getElement('sectionheader[2]')->_text;
        $this->assertStringContainsString('Light Reactions', $h2);
        $this->assertStringContainsString('3', $h2);

        // Blank title: bare "Section 2", no dangling colon.
        $h1 = $mform->getElement('sectionheader[1]')->_text;
        $this->assertSame('Section 2', $h1);
        $this->assertStringNotContainsString(':', $h1);
    }

    /**
     * A section title with HTML-significant characters is escaped in the header.
     *
     * @return void
     */
    public function test_section_header_title_is_escaped(): void {
        $this->resetAfterTest();
        $mform = $this->build([
            'sectioncount' => 1,
            'canapprove' => false,
            'sectiontitles' => ['A & B <x>'],
        ]);
        $h0 = $mform->getElement('sectionheader[0]')->_text;
        $this->assertStringNotContainsString('<x>', $h0);
        $this->assertStringContainsString('&amp;', $h0);
    }

    /**
     * The whole-blueprint actions live in their own region (Item 2): an
     * actionsheader precedes the button group, and per-section delete still
     * lives inside the section repeat.
     *
     * @return void
     */
    public function test_global_actions_have_their_own_region(): void {
        $this->resetAfterTest();
        $mform = $this->build([
            'sectioncount' => 2,
            'canapprove' => true,
            'sectiontitles' => ['One', 'Two'],
        ]);

        $this->assertTrue($mform->elementExists('actionsheader'));
        $this->assertTrue($mform->elementExists('buttonar'));
        // Per-section delete stays in the repeat.
        $this->assertTrue($mform->elementExists('sectiondelete[0]'));

        // The actions region comes after the last section header in element order.
        $order = array_keys($mform->_elementIndex);
        $this->assertGreaterThan(
            array_search('sectionheader[1]', $order, true),
            array_search('actionsheader', $order, true)
        );
        $this->assertGreaterThan(
            array_search('actionsheader', $order, true),
            array_search('buttonar', $order, true)
        );
    }
}
