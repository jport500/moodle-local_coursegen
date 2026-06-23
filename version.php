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
 * Version information for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_coursegen';
$plugin->version = 2026062403;
// Verified floor: only Moodle 5.2 (2026042000) on PHP 8.3 has been exercised. The
// code uses no 5.2-only APIs, but the declared floor reflects what is actually
// tested rather than an unverified 5.1/PHP 8.2 claim (see docs/DECISIONS D19).
$plugin->requires = 2026042000;
$plugin->maturity = MATURITY_BETA;
$plugin->release = 'v0.21.1';
// Dependency floors reconciled to reality (DECISIONS D32). Each is either a real
// minimum (the earliest version with the API surface coursegen calls) or a
// "verified floor" (the demo2-exercised version, where a true historical minimum
// could not be established) — mirroring the core requires honesty (D19). The prior
// format_pathway (2025021586) and local_quizgenpro (2026012301) numbers were stale
// guesses; both are below what is installed and could have allowed install against
// a version missing an API we call.
$plugin->dependencies = [
    // Generated courses default to format_pathway and set its pathwayshowsection0
    // course-format option (D10, D25). Real minimum: that option is present in
    // 1.0.1 (2026052000), the earliest exercised release; verified e2e on 1.0.2.
    'format_pathway' => 2026052000,
    // Assessments are delegated to local_quizgenpro's exporter::export_to_question_bank
    // (3-arg) and generator (D5, D10). Verified floor — the historical minimum for
    // that API surface is not establishable, so pin to the tested v3.1.0.
    'local_quizgenpro' => 2026051300,
    // Assessed sections place a formative knowledge check via questions::add and the
    // {knowledgecheck id=<uuid>} token (D15). Verified floor: matches the tested 1.0.2.
    'mod_knowledgecheck' => 2026051800,
    // Renders the inline {knowledgecheck} token. Verified floor: matches the tested 1.0.0.
    'filter_knowledgecheck' => 2026051800,
];
