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
 * Category course-builder hub: lists generation jobs and hosts the create form.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php'); // Draft-area file APIs are not loaded by default.

use local_coursegen\form\create_job_form;
use local_coursegen\local\job_manager;

$contextid = required_param('contextid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$context = context::instance_by_id($contextid);
if (!($context instanceof context_coursecat)) {
    throw new moodle_exception('error_badcontext', 'local_coursegen');
}

require_login();
// Builders (:generate) and reviewers (:reviewgate) may both reach the hub; only
// builders may create (enforced in the create action and on the Create button).
job_manager::require_access($context);

$huburl = new moodle_url('/local/coursegen/index.php', ['contextid' => $context->id]);
$createurl = new moodle_url('/local/coursegen/index.php', ['contextid' => $context->id, 'action' => 'create']);

$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(
    $context->get_context_name(false),
    new moodle_url('/course/management.php', ['categoryid' => $context->instanceid])
);
$PAGE->navbar->add(get_string('hubheading', 'local_coursegen'), $huburl);

if ($action === 'create') {
    require_capability('local/coursegen:generate', $context);
    $PAGE->set_url($createurl);
    $PAGE->set_title(get_string('createjob', 'local_coursegen'));
    $PAGE->set_heading(get_string('createjob', 'local_coursegen'));
    $PAGE->navbar->add(get_string('createjob', 'local_coursegen'), $createurl);

    $maxbytes = job_manager::max_source_bytes();
    $defaultmode = get_config('local_coursegen', 'default_mode') ?: 'outlinefirst';
    $modelocked = (bool) get_config('local_coursegen', 'lock_mode');
    // House defaults for the depth controls, clamped to known values (D26).
    $defaultlevel = \local_coursegen\local\course_depth::normalize_level(
        get_config('local_coursegen', 'default_audience_level') ?: null
    );
    $defaultdepth = \local_coursegen\local\course_depth::normalize_depth(
        get_config('local_coursegen', 'default_depth') ?: null
    );

    $draftitemid = file_get_submitted_draft_itemid('sources');
    file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'local_coursegen',
        'draft',
        null,
        create_job_form::filemanager_options($maxbytes)
    );

    $form = new create_job_form($createurl->out(false), [
        'filemanageroptions' => create_job_form::filemanager_options($maxbytes),
        'defaultmode' => $defaultmode,
        'modelocked' => $modelocked,
        'defaultlevel' => $defaultlevel,
        'defaultdepth' => $defaultdepth,
    ]);
    $form->set_data([
        'contextid' => $context->id,
        'sources' => $draftitemid,
        'mode' => $defaultmode,
        'audiencelevel' => $defaultlevel,
        'depth' => $defaultdepth,
    ]);

    if ($form->is_cancelled()) {
        redirect($huburl);
    } else if ($data = $form->get_data()) {
        $mode = $modelocked ? $defaultmode : $data->mode;
        $jobid = job_manager::create_job(
            $context,
            $USER->id,
            $mode,
            $data->topic ?? null,
            $data->sources ?? null,
            $data->audiencelevel ?? $defaultlevel,
            $data->depth ?? $defaultdepth,
            !empty($data->headerbanner)
        );
        // Land the operator on the job page so they watch it progress, rather
        // than a flash notification on a dead page.
        redirect(new moodle_url('/local/coursegen/view.php', ['jobid' => $jobid]));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('createjob', 'local_coursegen'));
    $form->display();
    echo $OUTPUT->footer();
    return;
}

// Inline restore (unarchive) from the archived view (D31): manager-only, POST.
if ($action === 'unarchive') {
    require_sesskey();
    job_manager::require_manage($context);
    job_manager::unarchive(required_param('jobid', PARAM_INT));
    redirect(
        new moodle_url($huburl, ['archived' => 1]),
        get_string('job_unarchived_ok', 'local_coursegen'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Hub: list the jobs in this category. Active by default; the archived view shows
// soft-deleted jobs with a Restore action (D31).
$showarchived = (bool) optional_param('archived', 0, PARAM_BOOL);
$canmanage = job_manager::can_manage($context);
$PAGE->set_url(new moodle_url($huburl, $showarchived ? ['archived' => 1] : []));
$PAGE->set_title(get_string('hubheading', 'local_coursegen'));
$PAGE->set_heading(get_string('hubheading', 'local_coursegen'));

$jobs = job_manager::jobs_in_context($context->id, $showarchived);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('hubheading', 'local_coursegen'));
if (!$showarchived && job_manager::can_create($context)) {
    echo html_writer::div(
        $OUTPUT->single_button($createurl, get_string('createjob', 'local_coursegen'), 'get'),
        'mb-3'
    );
}

// Toggle between the active and archived views.
echo html_writer::div(
    $showarchived
        ? html_writer::link($huburl, get_string('hub_hidearchived', 'local_coursegen'))
        : html_writer::link(
            new moodle_url($huburl, ['archived' => 1]),
            get_string('hub_showarchived', 'local_coursegen')
        ),
    'mb-3'
);

if (!$jobs) {
    echo $OUTPUT->notification(
        get_string($showarchived ? 'hub_noarchived' : 'hub_nojobs', 'local_coursegen'),
        'info'
    );
    echo $OUTPUT->footer();
    return;
}

$table = new html_table();
$table->head = [
    get_string('hub_col_job', 'local_coursegen'),
    get_string('hub_col_status', 'local_coursegen'),
    get_string('hub_col_updated', 'local_coursegen'),
];
if ($canmanage && $showarchived) {
    $table->head[] = '';
}
$table->attributes['class'] = 'generaltable';
foreach ($jobs as $job) {
    $title = ($job->title !== null && $job->title !== '')
        ? format_string($job->title)
        : get_string('hub_untitled', 'local_coursegen', $job->id);
    $jobpage = new moodle_url('/local/coursegen/view.php', ['jobid' => $job->id]);

    // Status cell, decorated with archived / course-deleted badges (D31).
    $status = get_string('status_' . $job->status, 'local_coursegen');
    $badges = [];
    if ($job->timecoursedeleted !== null) {
        $badges[] = html_writer::span(
            get_string('job_coursedeleted_badge', 'local_coursegen'),
            'badge bg-warning text-dark'
        );
    }
    if ($job->timearchived !== null) {
        $badges[] = html_writer::span(
            get_string('job_archived_badge', 'local_coursegen'),
            'badge bg-secondary'
        );
    }
    $statuscell = $badges ? $status . ' ' . implode(' ', $badges) : $status;

    $row = [
        html_writer::link($jobpage, $title),
        $statuscell,
        userdate($job->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
    ];
    if ($canmanage && $showarchived) {
        $restore = html_writer::start_tag('form', ['method' => 'post', 'action' => $huburl->out(false)]);
        $restore .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'unarchive']);
        $restore .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'jobid', 'value' => $job->id]);
        $restore .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $restore .= html_writer::empty_tag('input', ['type' => 'submit',
            'value' => get_string('job_unarchive_button', 'local_coursegen'), 'class' => 'btn btn-sm btn-secondary']);
        $restore .= html_writer::end_tag('form');
        $row[] = $restore;
    }
    $table->data[] = $row;
}
echo html_writer::table($table);
echo $OUTPUT->footer();
