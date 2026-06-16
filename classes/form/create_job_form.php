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
 * Minimal job-creation form: upload sources and/or enter a topic.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\form;

use local_coursegen\local\extractor\factory;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Deliberately minimal P1 entry point — the polished instructor experience
 * comes later. Accepts a topic prompt, file uploads, and a generation mode.
 */
class create_job_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'contextid');
        $mform->setType('contextid', PARAM_INT);

        $mform->addElement(
            'textarea',
            'topic',
            get_string('field_topic', 'local_coursegen'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('topic', PARAM_TEXT);
        $mform->addHelpButton('topic', 'field_topic', 'local_coursegen');

        $mform->addElement(
            'filemanager',
            'sources',
            get_string('field_sources', 'local_coursegen'),
            null,
            $this->_customdata['filemanageroptions']
        );

        $mform->addElement('select', 'mode', get_string('field_mode', 'local_coursegen'), [
            'outlinefirst' => get_string('mode_outlinefirst', 'local_coursegen'),
            'automatic' => get_string('mode_automatic', 'local_coursegen'),
        ]);
        $mform->setDefault('mode', $this->_customdata['defaultmode']);
        if (!empty($this->_customdata['modelocked'])) {
            $mform->freeze('mode');
        }

        $this->add_action_buttons(true, get_string('field_generate', 'local_coursegen'));
    }

    /**
     * Require at least one source: a non-empty topic or one uploaded file.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);

        $hastopic = isset($data['topic']) && trim($data['topic']) !== '';
        $hasfiles = false;
        if (!empty($data['sources'])) {
            $usercontext = \context_user::instance($USER->id);
            $fs = get_file_storage();
            $hasfiles = (bool) $fs->get_area_files(
                $usercontext->id,
                'user',
                'draft',
                $data['sources'],
                'id',
                false
            );
        }
        if (!$hastopic && !$hasfiles) {
            $errors['topic'] = get_string('error_nosource', 'local_coursegen');
        }
        return $errors;
    }

    /**
     * The filemanager options (accepted types, byte cap, file count).
     *
     * @param int $maxbytes Per-file byte cap.
     * @return array
     */
    public static function filemanager_options(int $maxbytes): array {
        return [
            'subdirs' => 0,
            'maxbytes' => $maxbytes,
            'maxfiles' => 20,
            'accepted_types' => factory::accepted_extensions(),
        ];
    }
}
