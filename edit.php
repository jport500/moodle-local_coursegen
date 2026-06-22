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
 * Review-gate editor: edit the blueprint, regenerate sections, and approve.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursegen\form\edit_blueprint_form;
use local_coursegen\local\ai\core_ai_text_client;
use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\job_manager;
use local_coursegen\local\review_gate;
use local_coursegen\local\section_regenerator;

$jobid = required_param('jobid', PARAM_INT);
$job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
$context = context::instance_by_id($job->contextid);

require_login();
// Editing and regeneration are open to authors (:generate) or reviewers (:reviewgate);
// only approval is restricted to :reviewgate (enforced in review_gate::approve()).
if (
    !has_capability('local/coursegen:generate', $context)
        && !has_capability('local/coursegen:reviewgate', $context)
) {
    require_capability('local/coursegen:reviewgate', $context);
}

$pageurl = new moodle_url('/local/coursegen/edit.php', ['jobid' => $jobid]);
$viewurl = new moodle_url('/local/coursegen/view.php', ['jobid' => $jobid]);
$huburl = new moodle_url('/local/coursegen/index.php', ['contextid' => $context->id]);
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editblueprint', 'local_coursegen'));
$PAGE->set_heading(get_string('editblueprint', 'local_coursegen'));
$PAGE->navbar->add(
    $context->get_context_name(false),
    new moodle_url('/course/management.php', ['categoryid' => $context->instanceid])
);
$PAGE->navbar->add(get_string('hubheading', 'local_coursegen'), $huburl);
$PAGE->navbar->add(get_string('hub_untitled', 'local_coursegen', $job->id), $viewurl);
$PAGE->navbar->add(get_string('editblueprint', 'local_coursegen'), $pageurl);

$blueprint = blueprint_store::load_current($jobid);
if ($blueprint === null) {
    throw new moodle_exception('noblueprint', 'local_coursegen', '', $job->status);
}

// Per-section regeneration (separate POST action; real reasoning call).
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'regen') {
    require_sesskey();
    $section = required_param('section', PARAM_INT);
    $ok = (new section_regenerator(new core_ai_text_client()))->regenerate($job, $section, $USER->id);
    redirect(
        $pageurl,
        get_string($ok ? 'regen_success' : 'regen_failed', 'local_coursegen', $section + 1),
        null,
        $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

$sections = $blueprint->get_sections();
$canapprove = ($job->status === job_manager::STATUS_AWAITING_REVIEW)
    && has_capability('local/coursegen:reviewgate', $context);

$form = new edit_blueprint_form($pageurl->out(false), [
    'sectioncount' => max(1, count($sections)),
    'canapprove' => $canapprove,
    // Section titles for the collapsible headers (Item 1); 0-indexed by position.
    'sectiontitles' => array_map(static fn(array $s): string => (string) $s['title'], array_values($sections)),
]);

// Prefill from the current blueprint.
$data = (object) [
    'jobid' => $jobid,
    'title' => $blueprint->get_title(),
    'description' => $blueprint->get_description(),
    'sectiontitle' => [],
    'sectionorder' => [],
    'sectionobjectives' => [],
    'sectionsummary' => [],
    'sectionimage' => [],
    'sectionimagehint' => [],
    'sectionassesstype' => [],
    'sectionassesscount' => [],
];
foreach ($sections as $i => $section) {
    $data->sectiontitle[$i] = $section['title'];
    $data->sectionorder[$i] = $i + 1;
    $data->sectionobjectives[$i] = implode("\n", $section['objectives']);
    $data->sectionsummary[$i] = $section['summary'];
    $data->sectionimage[$i] = !empty($section['image']['generate']) ? 1 : 0;
    $data->sectionimagehint[$i] = $section['image']['prompthint'];
    $data->sectionassesstype[$i] = $section['assessment']['type'];
    $data->sectionassesscount[$i] = $section['assessment']['questioncount'];
}
$form->set_data($data);

if ($form->is_cancelled()) {
    redirect($viewurl);
} else if ($submitted = $form->get_data()) {
    $edited = blueprint::from_form_data($submitted);
    if (!$edited->is_valid()) {
        redirect(
            $pageurl,
            get_string('error_blueprintinvalid', 'local_coursegen'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    blueprint_store::save_new_version($job, $edited, $USER->id);
    $DB->set_field('coursegen_job', 'estimatedspend', $edited->estimate_units(), ['id' => $job->id]);
    // An edit to an approved or already-built job sends it back for re-approval.
    review_gate::reopen_for_reedit($job, $USER->id);

    if (!empty($submitted->approvebutton) && $job->status === job_manager::STATUS_AWAITING_REVIEW) {
        review_gate::approve($job, $USER->id);
        redirect(
            $viewurl,
            get_string('approved_notice', 'local_coursegen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect(
        $viewurl,
        get_string('saved_notice', 'local_coursegen'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editblueprint', 'local_coursegen'));
echo html_writer::tag(
    'p',
    get_string('estimatedunits', 'local_coursegen', (int) $job->estimatedspend),
    ['class' => 'text-muted']
);
$form->display();

// Per-section regenerate buttons (each its own posted action).
echo $OUTPUT->heading(get_string('regen_heading', 'local_coursegen'), 4);
foreach ($sections as $i => $section) {
    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'regen', 'section' => $i, 'sesskey' => sesskey()]),
        get_string('regen_button', 'local_coursegen', ($i + 1) . '. ' . format_string($section['title'])),
        'post'
    );
}

echo $OUTPUT->footer();
