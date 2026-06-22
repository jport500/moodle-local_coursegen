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
 * Capability definitions for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Start a generation run that creates a (hidden/draft) course. Checked at
    // category level, mirroring moodle/course:create — generation is a
    // within-category act. RISK_SPAM/RISK_XSS: produces course content.
    'local/coursegen:generate' => [
        'riskbitmask'  => RISK_SPAM | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Approve a blueprint at the review gate so materialization may proceed
    // (outline-first mode). Same context level as :generate.
    'local/coursegen:reviewgate' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Configure plugin behaviour (tier→provider mappings, caps, mode lock).
    // Site-level administrative capability.
    'local/coursegen:configure' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Manage existing jobs: archive/unarchive, and the opt-in deletion of a
    // generated course (D31). More destructive than :generate/:reviewgate — it
    // can trigger delete_course() — so it is manager-only (not editingteacher)
    // and carries RISK_DATALOSS.
    'local/coursegen:manage' => [
        'riskbitmask'  => RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
