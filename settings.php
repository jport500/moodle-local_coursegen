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
 * Admin settings for local_coursegen.
 *
 * Capability-tier → provider mappings, generation caps and warning
 * thresholds, default mode + lock, per-section image opt-in default,
 * and the optional muprog/mucertify wrap toggles. All values are stored
 * via standard plugin config for automatic per-tenant isolation
 * (SPEC §3, DECISIONS D5/D7).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_coursegen', get_string('pluginname', 'local_coursegen'));
    $ADMIN->add('localplugins', $settings);

    // Capability-tier → provider mappings. Each tier may be pinned to a
    // configured AI Providers instance, or left as "AI Providers default" to
    // defer to the subsystem's own action routing (DECISIONS D5). The plugin
    // never hardcodes a vendor.
    $provideroptions = ['' => get_string('setting_provider_default', 'local_coursegen')];
    if (class_exists('\core_ai\manager')) {
        $aimanager = \core\di::get(\core_ai\manager::class);
        foreach ($aimanager->get_provider_records() as $record) {
            $provideroptions[$record->id] = format_string($record->name);
        }
    }

    $settings->add(new admin_setting_heading(
        'local_coursegen/heading_providers',
        get_string('setting_heading_providers', 'local_coursegen'),
        get_string('setting_heading_providers_desc', 'local_coursegen')
    ));

    $settings->add(new admin_setting_configselect(
        'local_coursegen/provider_image',
        get_string('setting_provider_image', 'local_coursegen'),
        get_string('setting_provider_image_desc', 'local_coursegen'),
        '',
        $provideroptions
    ));

    // Generation caps and warning thresholds (SPEC §7, DECISIONS D7).
    $settings->add(new admin_setting_heading(
        'local_coursegen/heading_caps',
        get_string('setting_heading_caps', 'local_coursegen'),
        get_string('setting_heading_caps_desc', 'local_coursegen')
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/cap_period_spend',
        get_string('setting_cap_period_spend', 'local_coursegen'),
        get_string('setting_cap_period_spend_desc', 'local_coursegen'),
        '500000',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/period_days',
        get_string('setting_period_days', 'local_coursegen'),
        get_string('setting_period_days_desc', 'local_coursegen'),
        '30',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/warn_threshold_pct',
        get_string('setting_warn_threshold_pct', 'local_coursegen'),
        get_string('setting_warn_threshold_pct_desc', 'local_coursegen'),
        '80',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/cap_image_count',
        get_string('setting_cap_image_count', 'local_coursegen'),
        get_string('setting_cap_image_count_desc', 'local_coursegen'),
        '100',
        PARAM_INT
    ));

    // Per-job source ingestion limits (SPEC §4) — per-tenant configurable.
    $settings->add(new admin_setting_heading(
        'local_coursegen/heading_limits',
        get_string('setting_heading_limits', 'local_coursegen'),
        get_string('setting_heading_limits_desc', 'local_coursegen')
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/max_source_bytes',
        get_string('setting_max_source_bytes', 'local_coursegen'),
        get_string('setting_max_source_bytes_desc', 'local_coursegen'),
        '20971520',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/max_corpus_tokens',
        get_string('setting_max_corpus_tokens', 'local_coursegen'),
        get_string('setting_max_corpus_tokens_desc', 'local_coursegen'),
        '200000',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_coursegen/reasoning_budget_tokens',
        get_string('setting_reasoning_budget_tokens', 'local_coursegen'),
        get_string('setting_reasoning_budget_tokens_desc', 'local_coursegen'),
        '12000',
        PARAM_INT
    ));

    // Generation mode and content defaults (SPEC §6, DECISIONS D3).
    $settings->add(new admin_setting_heading(
        'local_coursegen/heading_modes',
        get_string('setting_heading_modes', 'local_coursegen'),
        get_string('setting_heading_modes_desc', 'local_coursegen')
    ));

    $settings->add(new admin_setting_configselect(
        'local_coursegen/default_mode',
        get_string('setting_default_mode', 'local_coursegen'),
        get_string('setting_default_mode_desc', 'local_coursegen'),
        'outlinefirst',
        [
            'outlinefirst' => get_string('mode_outlinefirst', 'local_coursegen'),
            'automatic'    => get_string('mode_automatic', 'local_coursegen'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_coursegen/lock_mode',
        get_string('setting_lock_mode', 'local_coursegen'),
        get_string('setting_lock_mode_desc', 'local_coursegen'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_coursegen/image_optin_default',
        get_string('setting_image_optin_default', 'local_coursegen'),
        get_string('setting_image_optin_default_desc', 'local_coursegen'),
        0
    ));
}
