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
 * Library hooks for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add a "Course builder" entry to a course category's settings navigation for
 * users who may reach the builder there — a builder (:generate) or a reviewer
 * (:reviewgate).
 *
 * The entry point is a category-context hub, so the link is offered only on a
 * category and only to users who can access it — meaning it never appears where
 * it would not work.
 *
 * @param navigation_node $navigation The category settings node to extend.
 * @param context_coursecat $context The category context.
 * @return void
 */
function local_coursegen_extend_navigation_category_settings(navigation_node $navigation, context_coursecat $context) {
    if (!\local_coursegen\local\job_manager::can_access($context)) {
        return;
    }
    $navigation->add(
        get_string('hubheading', 'local_coursegen'),
        new moodle_url('/local/coursegen/index.php', ['contextid' => $context->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_coursegen_create',
        new pix_icon('i/addblock', '')
    );
}
