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
 * Developer CLI for the review gate: inspect status, regenerate a section
 * (real reasoning call), and approve. Doubles as the real-transport smoke.
 *
 * Example:
 *   php cli/gate.php --jobid=42
 *   php cli/gate.php --jobid=42 --regen=0
 *   php cli/gate.php --jobid=42 --approve
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\local\ai\core_ai_text_client;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\review_gate;
use local_coursegen\local\section_regenerator;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'jobid' => 0,
    'regen' => -1,
    'approve' => false,
], ['h' => 'help']);

if ($options['help'] || !$options['jobid']) {
    cli_writeln("Drive the review gate for a job.\n");
    cli_writeln("  --jobid=ID    The coursegen job id.");
    cli_writeln("  --regen=N     Regenerate section N (0-based) via a real reasoning call.");
    cli_writeln("  --approve     Approve the current blueprint.");
    cli_writeln("  -h, --help    This help.");
    exit(0);
}

$job = $DB->get_record('coursegen_job', ['id' => (int) $options['jobid']], '*', MUST_EXIST);
$user = core_user::get_user($job->userid, '*', MUST_EXIST);
\core\cron::setup_user($user);
cli_writeln("Job {$job->id} status: {$job->status}, mode: {$job->mode}");
cli_writeln('Effective mode: ' . review_gate::effective_mode($job));

if ((int) $options['regen'] >= 0) {
    $index = (int) $options['regen'];
    cli_writeln("Regenerating section {$index} (real provider call)...");
    $ok = (new section_regenerator(new core_ai_text_client()))->regenerate($job, $index, (int) $user->id);
    cli_writeln('Regenerate result: ' . ($ok ? 'success' : 'FAILED'));
    $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
    cli_writeln("Job status now: {$job->status}");
}

if ($options['approve']) {
    cli_writeln('Approving...');
    review_gate::approve($job, (int) $user->id);
    $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
    cli_writeln("Job status now: {$job->status}");
}

$blueprint = blueprint_store::load_current($job->id);
if ($blueprint) {
    cli_writeln("Current blueprint: \"{$blueprint->get_title()}\", {$blueprint->section_count()} sections, "
        . "estimate {$blueprint->estimate_units()} units.");
}
exit(0);
