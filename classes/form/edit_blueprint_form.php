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
 * Editing form for the blueprint IR (course + ordered sections).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

use local_coursegen\local\blueprint;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * A native moodleform using repeat_elements for the ordered sections (the
 * tool_musudo house pattern): edit course title/description, reorder via a
 * per-section order field, rename/edit fields, add via "add section", and
 * remove via the per-section delete button. The data→IR conversion lives in
 * {@see blueprint::from_form_data()} so it is unit-testable without the form.
 */
class edit_blueprint_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'jobid');
        $mform->setType('jobid', PARAM_INT);

        $mform->addElement(
            'text',
            'title',
            get_string('field_coursetitle', 'local_coursegen'),
            ['size' => 60]
        );
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'description',
            get_string('field_coursedescription', 'local_coursegen'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('description', PARAM_TEXT);

        $assesstypes = [
            blueprint::ASSESS_NONE => get_string('assess_none', 'local_coursegen'),
            blueprint::ASSESS_KNOWLEDGECHECK => get_string('assess_knowledgecheck', 'local_coursegen'),
            blueprint::ASSESS_QUIZ => get_string('assess_quiz', 'local_coursegen'),
        ];

        $repeat = [];
        $repeat[] = $mform->createElement(
            'header',
            'sectionheader',
            // The {no} token is replaced with the row number by repeat_elements; a
            // loaded section's title is appended after repeat_elements below (Item 1),
            // so a blank/just-added row keeps the bare "Section N".
            get_string('section_heading', 'local_coursegen') . ' {no}'
        );
        $repeat[] = $mform->createElement(
            'text',
            'sectiontitle',
            get_string('field_sectiontitle', 'local_coursegen'),
            ['size' => 50]
        );
        $repeat[] = $mform->createElement(
            'text',
            'sectionorder',
            get_string('field_sectionorder', 'local_coursegen'),
            ['size' => 3]
        );
        $repeat[] = $mform->createElement(
            'textarea',
            'sectionobjectives',
            get_string('field_objectives', 'local_coursegen'),
            ['rows' => 3, 'cols' => 50]
        );
        $repeat[] = $mform->createElement(
            'textarea',
            'sectionsummary',
            get_string('field_summary', 'local_coursegen'),
            ['rows' => 2, 'cols' => 50]
        );
        $repeat[] = $mform->createElement(
            'advcheckbox',
            'sectionimage',
            get_string('field_image', 'local_coursegen')
        );
        $repeat[] = $mform->createElement(
            'text',
            'sectionimagehint',
            get_string('field_imagehint', 'local_coursegen'),
            ['size' => 50]
        );
        $repeat[] = $mform->createElement(
            'select',
            'sectionassesstype',
            get_string('field_assessment', 'local_coursegen'),
            $assesstypes
        );
        $repeat[] = $mform->createElement(
            'text',
            'sectionassesscount',
            get_string('field_questioncount', 'local_coursegen'),
            ['size' => 3]
        );
        $repeat[] = $mform->createElement(
            'submit',
            'sectiondelete',
            get_string('section_delete', 'local_coursegen'),
            [],
            false
        );

        $repeatopts = [
            'sectiontitle' => ['type' => PARAM_TEXT],
            'sectionorder' => [
                'type' => PARAM_INT,
                'helpbutton' => ['field_sectionorder', 'local_coursegen'],
            ],
            'sectionobjectives' => ['type' => PARAM_TEXT],
            'sectionsummary' => ['type' => PARAM_TEXT],
            'sectionimagehint' => ['type' => PARAM_TEXT],
            'sectionassesstype' => ['type' => PARAM_ALPHA],
            'sectionassesscount' => ['type' => PARAM_INT],
            // Note: no 'expanded' opt here — it applies only to header elements;
            // on the delete submit button it was a no-op that emitted a debugging.
        ];

        $count = max(1, (int) ($this->_customdata['sectioncount'] ?? 1));
        $this->repeat_elements(
            $repeat,
            $count,
            $repeatopts,
            'section_repeats',
            'section_addmore',
            1,
            get_string('section_addmore', 'local_coursegen'),
            true,
        );

        // Item 1: label each loaded section's collapsible header "Section N: <title>"
        // so the rows are distinguishable when collapsed. The {no} template already
        // supplied the number; this appends the title from customdata (reload-time,
        // no JS). A blank title (e.g. a just-added row) keeps the bare "Section N".
        foreach ((array) ($this->_customdata['sectiontitles'] ?? []) as $i => $title) {
            $title = trim((string) $title);
            if ($title === '' || !$mform->elementExists("sectionheader[$i]")) {
                continue;
            }
            $mform->getElement("sectionheader[$i]")->setValue(get_string(
                'section_heading_titled',
                'local_coursegen',
                (object) ['no' => $i + 1, 'title' => format_string($title)]
            ));
        }

        // Item 2: a fresh, always-expanded region for the whole-blueprint actions, so
        // Save/Approve/Add section are never hidden inside the last section's collapsed
        // fieldset. Per-section Delete stays inside the repeat (it is per-section).
        $mform->addElement('header', 'actionsheader', get_string('form_actions', 'local_coursegen'));
        $mform->setExpanded('actionsheader', true);

        $buttonarray = [];
        $buttonarray[] = $mform->createElement(
            'submit',
            'savebutton',
            get_string('savechanges')
        );
        if (!empty($this->_customdata['canapprove'])) {
            $buttonarray[] = $mform->createElement(
                'submit',
                'approvebutton',
                get_string('action_approve', 'local_coursegen')
            );
        }
        $buttonarray[] = $mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
    }
}
