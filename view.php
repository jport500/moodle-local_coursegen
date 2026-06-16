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
 * Read-only view of a job's current blueprint (verification only; editing is P3).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursegen\local\blueprint;

$jobid = required_param('jobid', PARAM_INT);
$job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
$context = context::instance_by_id($job->contextid);

require_login();
require_capability('local/coursegen:generate', $context);

$url = new moodle_url('/local/coursegen/view.php', ['jobid' => $jobid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('blueprintview', 'local_coursegen'));
$PAGE->set_heading(get_string('blueprintview', 'local_coursegen'));

$record = $DB->get_record('coursegen_blueprint', ['jobid' => $jobid, 'iscurrent' => 1]);

echo $OUTPUT->header();

if (!$record) {
    echo $OUTPUT->notification(get_string('noblueprint', 'local_coursegen', $job->status), 'info');
    echo $OUTPUT->footer();
    return;
}

$blueprint = blueprint::from_json($record->content);

echo $OUTPUT->heading(format_string($blueprint->get_title()));
if ($blueprint->get_description() !== '') {
    echo html_writer::tag('p', format_text($blueprint->get_description(), FORMAT_PLAIN));
}
if ($job->estimatedspend !== null) {
    echo html_writer::tag('p', get_string('estimatedunits', 'local_coursegen', (int) $job->estimatedspend));
}

foreach ($blueprint->get_sections() as $i => $section) {
    echo $OUTPUT->heading(($i + 1) . '. ' . format_string($section['title']), 4);
    if ($section['summary'] !== '') {
        echo html_writer::tag('p', format_text($section['summary'], FORMAT_PLAIN));
    }
    if ($section['objectives']) {
        $items = array_map(static fn(string $o): string => html_writer::tag('li', s($o)), $section['objectives']);
        echo html_writer::tag('ul', implode('', $items));
    }
    $meta = get_string('field_mode', 'local_coursegen') . ': ' . s($section['contenttype']);
    if (!empty($section['image']['generate'])) {
        $meta .= ' · ' . get_string('blueprintimage', 'local_coursegen');
    }
    if ($section['assessment']['type'] === blueprint::ASSESS_QUIZ) {
        $meta .= ' · ' . get_string('blueprintquiz', 'local_coursegen', (int) $section['assessment']['questioncount']);
    }
    echo html_writer::tag('p', $meta, ['class' => 'text-muted']);
}

echo $OUTPUT->footer();
