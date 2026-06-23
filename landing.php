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
 * Course-builder landing page (Surface 1, D35): a category picker reached from
 * Site administration > Courses. Lists the categories the operator can build in
 * (the same per-category access the hub uses) and routes into the per-category
 * hub. An additional door into already-gated pages — index.php re-gates.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_coursegen\local\job_manager;

// Sets the system-context page, requires login, and enforces the coarse admin
// capability (moodle/course:create) registered with the external page.
admin_externalpage_setup('local_coursegen_builder');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('landing_heading', 'local_coursegen'));

// The real gate: list only the categories where the operator can actually build
// (job_manager::can_access — the same definition as the category hub).
$categories = job_manager::buildable_categories();

if (!$categories) {
    // An operator with builder rights in zero categories: a clear state, not an error.
    echo $OUTPUT->notification(get_string('landing_none', 'local_coursegen'), 'info');
} else {
    echo html_writer::tag('p', get_string('landing_intro', 'local_coursegen'));
    $links = [];
    foreach ($categories as $contextid => $name) {
        $url = new moodle_url('/local/coursegen/index.php', ['contextid' => $contextid]);
        $links[] = html_writer::tag('li', html_writer::link($url, $name));
    }
    echo html_writer::tag('ul', implode('', $links));
}

echo $OUTPUT->footer();
