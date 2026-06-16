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
 * English language strings for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['coursegen:configure'] = 'Configure the AI course builder';
$string['coursegen:generate'] = 'Generate courses with the AI course builder';
$string['coursegen:reviewgate'] = 'Approve a generation blueprint for materialization';
$string['mode_automatic'] = 'Automatic (no review gate)';
$string['mode_outlinefirst'] = 'Outline first (review the blueprint before building)';
$string['pluginname'] = 'AI course builder';
$string['privacy:metadata:coursegen_blueprint'] = 'The editable generation plan (blueprint) authored for a generation job.';
$string['privacy:metadata:coursegen_blueprint:content'] = 'The serialized plan: sections, objectives, content types, and assessment spec.';
$string['privacy:metadata:coursegen_blueprint:intro'] = 'The proposed course description.';
$string['privacy:metadata:coursegen_blueprint:timecreated'] = 'The time this blueprint version was created.';
$string['privacy:metadata:coursegen_blueprint:title'] = 'The proposed course title.';
$string['privacy:metadata:coursegen_job'] = 'One record per course-generation run requested by a user.';
$string['privacy:metadata:coursegen_job:actualspend'] = 'The actual generation cost accrued by the run.';
$string['privacy:metadata:coursegen_job:courseid'] = 'The course created by the run, once materialized.';
$string['privacy:metadata:coursegen_job:estimatedspend'] = 'The estimated generation cost shown to the user.';
$string['privacy:metadata:coursegen_job:mode'] = 'The generation mode chosen for the run.';
$string['privacy:metadata:coursegen_job:status'] = 'The current status of the run.';
$string['privacy:metadata:coursegen_job:timecreated'] = 'The time the run was requested.';
$string['privacy:metadata:coursegen_job:userid'] = 'The user who requested the generation run.';
$string['privacy:metadata:coursegen_log'] = 'A per-stage audit of generation activity. Credential values are never stored.';
$string['privacy:metadata:coursegen_log:actionname'] = 'The AI action invoked, where applicable.';
$string['privacy:metadata:coursegen_log:detail'] = 'A non-sensitive note about the logged step.';
$string['privacy:metadata:coursegen_log:model'] = 'The resolved AI model, where reported.';
$string['privacy:metadata:coursegen_log:outcome'] = 'Whether the logged step succeeded or failed.';
$string['privacy:metadata:coursegen_log:provider'] = 'The resolved AI provider, where reported.';
$string['privacy:metadata:coursegen_log:stage'] = 'The pipeline stage the log entry belongs to.';
$string['privacy:metadata:coursegen_log:tier'] = 'The capability tier used for the step.';
$string['privacy:metadata:coursegen_log:timecreated'] = 'The time the step was logged.';
$string['privacy:metadata:coursegen_log:userid'] = 'The user who triggered the logged step, when attributable.';
$string['privacy:metadata:coursegen_source'] = 'References to source material a user supplied for a generation job.';
$string['privacy:metadata:coursegen_source:extractedchars'] = 'The size of the text corpus extracted from the source.';
$string['privacy:metadata:coursegen_source:filename'] = 'The original filename of an uploaded source.';
$string['privacy:metadata:coursegen_source:itemid'] = 'The File API item identifier for the stored source file.';
$string['privacy:metadata:coursegen_source:status'] = 'The extraction status of the source.';
$string['privacy:metadata:coursegen_source:timecreated'] = 'The time the source was added.';
$string['privacy:metadata:coursegen_source:type'] = 'The source type (document, pasted text, or topic prompt).';
$string['privacy:metadata:filearea_source'] = 'Files uploaded by a user as source material for course generation.';
$string['privacy:subcontext'] = 'AI course builder';
$string['setting_cap_image_count'] = 'Image generation cap';
$string['setting_cap_image_count_desc'] = 'Maximum number of AI-generated images allowed per period. Images carry a higher unit cost, so this sub-cap is separate from the spend cap.';
$string['setting_cap_period_spend'] = 'Generation spend cap';
$string['setting_cap_period_spend_desc'] = 'Hard cap on estimated generation spend per period, in the provider billing unit. Generation stops when the cap is reached. Administrators can raise it.';
$string['setting_default_mode'] = 'Default generation mode';
$string['setting_default_mode_desc'] = 'The mode new generation jobs start in. Outline first pauses for blueprint review; automatic proceeds straight through. Generated courses are always created hidden.';
$string['setting_heading_caps'] = 'Generation caps';
$string['setting_heading_caps_desc'] = 'Per-tenant spend and image limits with a soft-warning threshold (SPEC §7).';
$string['setting_heading_compose'] = 'Composition with the stack';
$string['setting_heading_compose_desc'] = 'Optional finalize steps that wrap a generated course into a program or certification (off by default).';
$string['setting_heading_modes'] = 'Generation mode and content defaults';
$string['setting_heading_modes_desc'] = 'Defaults for new generation jobs.';
$string['setting_heading_providers'] = 'Capability-tier providers';
$string['setting_heading_providers_desc'] = 'Map each capability tier to a configured AI provider, or leave it on the AI Providers default to use the subsystem\'s own routing. The plugin never hardcodes a vendor.';
$string['setting_image_optin_default'] = 'Generate section images by default';
$string['setting_image_optin_default_desc'] = 'Whether per-section image generation is opted in by default. Image generation is opt-in per section regardless.';
$string['setting_lock_mode'] = 'Lock generation mode';
$string['setting_lock_mode_desc'] = 'Prevent users from overriding the default generation mode on a per-run basis.';
$string['setting_provider_default'] = 'AI Providers default';
$string['setting_provider_drafting'] = 'Drafting tier provider';
$string['setting_provider_drafting_desc'] = 'Provider for bulk per-section reading content. Higher volume and cost-sensitive — a cheaper model is appropriate here.';
$string['setting_provider_image'] = 'Image tier provider';
$string['setting_provider_image_desc'] = 'Provider for diagram and illustration generation.';
$string['setting_provider_reasoning'] = 'Reasoning tier provider';
$string['setting_provider_reasoning_desc'] = 'Provider for blueprint generation. Low volume and high leverage — a strong model is appropriate here.';
$string['setting_warn_threshold_pct'] = 'Warning threshold (%)';
$string['setting_warn_threshold_pct_desc'] = 'Percentage of the spend cap at which a soft warning is shown before the hard cap is reached.';
$string['setting_wrap_mucertify'] = 'Offer "wrap in certification"';
$string['setting_wrap_mucertify_desc'] = 'Allow a generated course to be wrapped into a tool_mucertify certification as an optional finalize step. Off by default.';
$string['setting_wrap_muprog'] = 'Offer "wrap in program"';
$string['setting_wrap_muprog_desc'] = 'Allow a generated course to be wrapped into a tool_muprog program as an optional finalize step. Off by default.';
