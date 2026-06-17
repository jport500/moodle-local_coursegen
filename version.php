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
$plugin->version = 2026061617;
// Verified floor: only Moodle 5.2 (2026042000) on PHP 8.3 has been exercised. The
// code uses no 5.2-only APIs, but the declared floor reflects what is actually
// tested rather than an unverified 5.1/PHP 8.2 claim (see docs/DECISIONS D19).
$plugin->requires = 2026042000;
$plugin->maturity = MATURITY_BETA;
$plugin->release = 'v0.12.1';
$plugin->dependencies = [
    // Generated courses default to the format_pathway course format (DECISIONS D10).
    'format_pathway' => 2025021586,
    // Assessments are delegated to local_quizgenpro's API (DECISIONS D5, D10).
    'local_quizgenpro' => 2026012301,
    // Assessed sections are placed as formative knowledge checks (DECISIONS D15).
    'mod_knowledgecheck' => 2026051800,
    'filter_knowledgecheck' => 2026051800,
    // The tool_muprog / tool_mucertify plugins are deliberately NOT listed: the
    // cert-chain wrap is optional and off by default, so it must not force the
    // cert stack onto every tenant. local\cert_wrap soft-checks for them at
    // runtime and skips with a warning when a toggle is on but the plugin/API is
    // absent (DECISIONS D17).
];
