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
 * Minimal entry point: create a generation job from uploads and/or a topic.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursegen\form\create_job_form;
use local_coursegen\local\job_manager;

$contextid = required_param('contextid', PARAM_INT);
$context = context::instance_by_id($contextid);
if (!($context instanceof context_coursecat)) {
    throw new moodle_exception('error_badcontext', 'local_coursegen');
}

require_login();
require_capability('local/coursegen:generate', $context);

$url = new moodle_url('/local/coursegen/index.php', ['contextid' => $context->id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_coursegen'));
$PAGE->set_heading(get_string('createjob', 'local_coursegen'));

$maxbytes = job_manager::max_source_bytes();
$defaultmode = get_config('local_coursegen', 'default_mode') ?: 'outlinefirst';
$modelocked = (bool) get_config('local_coursegen', 'lock_mode');

$draftitemid = file_get_submitted_draft_itemid('sources');
file_prepare_draft_area(
    $draftitemid,
    $context->id,
    'local_coursegen',
    'draft',
    null,
    create_job_form::filemanager_options($maxbytes)
);

$form = new create_job_form($url->out(false), [
    'filemanageroptions' => create_job_form::filemanager_options($maxbytes),
    'defaultmode' => $defaultmode,
    'modelocked' => $modelocked,
]);
$form->set_data([
    'contextid' => $context->id,
    'sources' => $draftitemid,
    'mode' => $defaultmode,
]);

if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    $mode = $modelocked ? $defaultmode : $data->mode;
    $jobid = job_manager::create_job(
        $context,
        $USER->id,
        $mode,
        $data->topic ?? null,
        $data->sources ?? null
    );
    redirect(
        $url,
        get_string('jobqueued', 'local_coursegen', $jobid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('createjob', 'local_coursegen'));
$form->display();
echo $OUTPUT->footer();
