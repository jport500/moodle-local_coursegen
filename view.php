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
 * Status-aware job page: dispatches on the job's status to a progress view, the
 * review gate, the built course, or the failure reason (wayfinding).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursegen\local\blueprint;
use local_coursegen\local\job_manager;

/** @var int Seconds between auto-refreshes while a job is processing. */
const LOCAL_COURSEGEN_REFRESH_SECONDS = 10;

$jobid = required_param('jobid', PARAM_INT);
$job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
$context = context::instance_by_id($job->contextid);

require_login();
job_manager::require_access($context);

$url = new moodle_url('/local/coursegen/view.php', ['jobid' => $jobid]);
$huburl = new moodle_url('/local/coursegen/index.php', ['contextid' => $context->id]);
$phase = job_manager::classify_status($job->status);

$record = $DB->get_record('coursegen_blueprint', ['jobid' => $jobid, 'iscurrent' => 1]);
$blueprint = $record ? blueprint::from_json($record->content) : null;
$heading = $blueprint ? format_string($blueprint->get_title()) : get_string('hub_untitled', 'local_coursegen', $job->id);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);
$PAGE->navbar->add(
    $context->get_context_name(false),
    new moodle_url('/course/management.php', ['categoryid' => $context->instanceid])
);
$PAGE->navbar->add(get_string('hubheading', 'local_coursegen'), $huburl);
$PAGE->navbar->add($heading, $url);

// While the pipeline is running, refresh so the page reflects each scheduled-task step.
if ($phase === job_manager::PHASE_PROCESSING) {
    $PAGE->set_periodic_refresh_delay(LOCAL_COURSEGEN_REFRESH_SECONDS);
}

$canreview = has_capability('local/coursegen:reviewgate', $context)
    || has_capability('local/coursegen:generate', $context);
$editurl = new moodle_url('/local/coursegen/edit.php', ['jobid' => $job->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo html_writer::tag(
    'p',
    get_string('jobstatus', 'local_coursegen', get_string('status_' . $job->status, 'local_coursegen')),
    ['class' => 'text-muted']
);

// Phase-specific guidance and the primary call to action.
switch ($phase) {
    case job_manager::PHASE_PROCESSING:
        echo $OUTPUT->notification(get_string('jobpage_processing', 'local_coursegen'), 'info');
        break;

    case job_manager::PHASE_REVIEW:
        echo $OUTPUT->notification(get_string('jobpage_review', 'local_coursegen'), 'info');
        if ($canreview) {
            echo html_writer::div(
                $OUTPUT->single_button($editurl, get_string('jobpage_reviewbutton', 'local_coursegen'), 'get'),
                'mb-3'
            );
        }
        break;

    case job_manager::PHASE_COMPLETE:
        // A re-materialize that was refused (D18/D20) leaves the job complete with
        // the existing course intact — surface that the rebuild was declined, but
        // not the benign in-build skip failures a good course also carries.
        $refusal = job_manager::current_refusal($job->id);
        if ($refusal !== null) {
            echo $OUTPUT->notification(get_string('jobpage_refused', 'local_coursegen', $refusal), 'warning');
        }
        echo $OUTPUT->notification(get_string('jobpage_complete', 'local_coursegen'), 'success');
        if ($job->courseid && $DB->record_exists('course', ['id' => $job->courseid])) {
            echo html_writer::div(
                $OUTPUT->single_button(
                    new moodle_url('/course/view.php', ['id' => $job->courseid]),
                    get_string('jobpage_opencourse', 'local_coursegen'),
                    'get'
                ),
                'mb-3'
            );
        }
        break;

    case job_manager::PHASE_FAILED:
        $reason = job_manager::failure_reason($job->id);
        echo $OUTPUT->notification(
            get_string('jobpage_failed', 'local_coursegen', $reason ?? get_string('jobpage_failed_noreason', 'local_coursegen')),
            'error'
        );
        break;
}

// The blueprint (once it exists) is shown for every phase, reusing the same layout.
if ($blueprint) {
    if ($job->estimatedspend !== null) {
        echo html_writer::tag('p', get_string('estimatedunits', 'local_coursegen', (int) $job->estimatedspend));
    }
    if ($blueprint->get_description() !== '') {
        echo html_writer::tag('p', format_text($blueprint->get_description(), FORMAT_PLAIN));
    }

    // An "Edit blueprint" link where editing still makes sense (not mid-build).
    if ($canreview && $phase !== job_manager::PHASE_PROCESSING && $phase !== job_manager::PHASE_REVIEW) {
        echo html_writer::div($OUTPUT->single_button(
            $editurl,
            get_string('editblueprint', 'local_coursegen'),
            'get'
        ));
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
        $meta = [];
        if (!empty($section['image']['generate'])) {
            $meta[] = get_string('blueprintimage', 'local_coursegen');
        }
        if ($section['assessment']['type'] === blueprint::ASSESS_QUIZ) {
            $meta[] = get_string('blueprintquiz', 'local_coursegen', (int) $section['assessment']['questioncount']);
        }
        if ($meta) {
            echo html_writer::tag('p', implode(' · ', $meta), ['class' => 'text-muted']);
        }
    }
}

echo $OUTPUT->footer();
