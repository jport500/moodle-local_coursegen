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

$string['action_approve'] = 'Approve';
$string['approved_notice'] = 'Blueprint approved; the course is ready to be built.';
$string['assess_knowledgecheck'] = 'Knowledge check';
$string['assess_none'] = 'No assessment';
$string['assess_quiz'] = 'Graded quiz';
$string['blueprintimage'] = 'Image planned';
$string['blueprintknowledgecheck'] = 'Knowledge check: {$a} questions';
$string['blueprintquiz'] = 'Graded quiz: {$a} questions';
$string['blueprintview'] = 'Generated blueprint';
$string['coursegen:configure'] = 'Configure the AI course builder';
$string['coursegen:generate'] = 'Generate courses with the AI course builder';
$string['coursegen:reviewgate'] = 'Approve a generation blueprint for materialization';
$string['createjob'] = 'Create a generation job';
$string['editblueprint'] = 'Edit / review blueprint';
$string['error_badcontext'] = 'The course builder must be opened in a course category context.';
$string['error_blueprintinvalid'] = 'The blueprint needs a course title and at least one section.';
$string['error_nosource'] = 'Provide a topic or upload at least one source file.';
$string['error_notawaitingreview'] = 'This job is not awaiting review.';
$string['error_sourcetoolarge'] = 'The uploaded sources exceed the per-job limit ({$a}).';
$string['error_unsupportedtype'] = 'Unsupported source file type: {$a}.';
$string['estimatedunits'] = 'Estimated generation units: {$a}';
$string['extractionfailed'] = 'Text could not be extracted from a source file.';
$string['field_assessment'] = 'Assessment';
$string['field_coursedescription'] = 'Course description';
$string['field_coursetitle'] = 'Course title';
$string['field_generate'] = 'Create job';
$string['field_image'] = 'Generate an image for this section';
$string['field_imagehint'] = 'Image prompt hint';
$string['field_mode'] = 'Generation mode';
$string['field_objectives'] = 'Learning objectives (one per line)';
$string['field_questioncount'] = 'Number of questions';
$string['field_sectionorder'] = 'Order';
$string['field_sectiontitle'] = 'Section title';
$string['field_sources'] = 'Source files';
$string['field_summary'] = 'Summary';
$string['field_topic'] = 'Topic';
$string['field_topic_help'] = 'Optionally describe the course topic. You can provide a topic, upload source files, or both. A topic-only job skips extraction and uses the prompt as its corpus.';
$string['finalsection_body'] = 'You\'ve reached the end of this course. Revisit any section you\'d like, and well done on completing it.';
$string['finalsection_name'] = 'Wrap-up';
$string['hub_col_job'] = 'Job';
$string['hub_col_status'] = 'Status';
$string['hub_col_updated'] = 'Last updated';
$string['hub_nojobs'] = 'No generation jobs in this category yet. Create one to get started.';
$string['hub_untitled'] = 'Job #{$a}';
$string['hubheading'] = 'Course builder';
$string['introsection_covers'] = 'What you\'ll cover';
$string['introsection_name'] = 'Introduction';
$string['jobpage_complete'] = 'The course has been built. It is hidden from learners — open it to review, then unhide it when you are ready.';
$string['jobpage_failed'] = 'Generation failed: {$a}';
$string['jobpage_failed_noreason'] = 'no reason recorded';
$string['jobpage_opencourse'] = 'Open the course';
$string['jobpage_processing'] = 'This job is being generated. It advances automatically as scheduled tasks run — this page refreshes itself, so you can leave it open.';
$string['jobpage_refused'] = 'Your most recent rebuild was declined and the existing course was kept: {$a}';
$string['jobpage_review'] = 'The blueprint is ready for your review.';
$string['jobpage_reviewbutton'] = 'Review & approve';
$string['jobqueued'] = 'Generation job {$a} created; source extraction has been queued.';
$string['jobstatus'] = 'Status: {$a}';
$string['kcname'] = 'Knowledge check: {$a}';
$string['mode_automatic'] = 'Automatic (no review gate)';
$string['mode_outlinefirst'] = 'Outline first (review the blueprint before building)';
$string['noblueprint'] = 'No blueprint has been generated yet (job status: {$a}).';
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
$string['privacy:metadata:coursegen_source:corpus'] = 'The normalized text and structure extracted from the source.';
$string['privacy:metadata:coursegen_source:extractedchars'] = 'The size of the text corpus extracted from the source.';
$string['privacy:metadata:coursegen_source:filename'] = 'The original filename of an uploaded source.';
$string['privacy:metadata:coursegen_source:itemid'] = 'The File API item identifier for the stored source file.';
$string['privacy:metadata:coursegen_source:status'] = 'The extraction status of the source.';
$string['privacy:metadata:coursegen_source:timecreated'] = 'The time the source was added.';
$string['privacy:metadata:coursegen_source:type'] = 'The source type (document, pasted text, or topic prompt).';
$string['privacy:metadata:filearea_source'] = 'Files uploaded by a user as source material for course generation.';
$string['privacy:subcontext'] = 'AI course builder';
$string['quizname'] = 'Quiz: {$a}';
$string['regen_button'] = 'Regenerate {$a}';
$string['regen_failed'] = 'Could not regenerate section {$a}.';
$string['regen_heading'] = 'Regenerate a section';
$string['regen_success'] = 'Section {$a} regenerated; review the updated blueprint.';
$string['saved_notice'] = 'Blueprint saved.';
$string['section_addmore'] = 'Add section';
$string['section_delete'] = 'Delete this section';
$string['section_heading'] = 'Section';
$string['setting_cap_image_count'] = 'Image generation cap';
$string['setting_cap_image_count_desc'] = 'Maximum number of AI-generated images allowed per period. Images carry a higher unit cost, so this sub-cap is separate from the spend cap. Set to 0 for unlimited.';
$string['setting_cap_period_spend'] = 'Generation spend cap';
$string['setting_cap_period_spend_desc'] = 'Hard cap on generation spend per rolling period, in generation units (roughly four characters per token). Spend is counted from the audit log over the period window, so it resets as old entries age out. Generation is blocked once the period total would exceed this cap. Set to 0 for unlimited; administrators can raise it.';
$string['setting_default_mode'] = 'Default generation mode';
$string['setting_default_mode_desc'] = 'The mode new generation jobs start in. Outline first pauses for blueprint review; automatic proceeds straight through. Generated courses are always created hidden.';
$string['setting_heading_caps'] = 'Generation caps';
$string['setting_heading_caps_desc'] = 'Per-tenant spend and image limits with a soft-warning threshold (SPEC §7).';
$string['setting_heading_limits'] = 'Source limits';
$string['setting_heading_limits_desc'] = 'Per-job caps on uploaded source size and extracted corpus size (SPEC §4).';
$string['setting_heading_modes'] = 'Generation mode and content defaults';
$string['setting_heading_modes_desc'] = 'Defaults for new generation jobs.';
$string['setting_heading_providers'] = 'AI provider';
$string['setting_heading_providers_desc'] = 'Text generation uses the AI Providers subsystem\'s configured text provider. core_ai routes per action (not per call), so only the separate image path is selectable here; leave it on the default to use the subsystem\'s own routing. The plugin never hardcodes a vendor.';
$string['setting_image_optin_default'] = 'Generate section images by default';
$string['setting_image_optin_default_desc'] = 'Whether per-section image generation is opted in by default. Image generation is opt-in per section regardless.';
$string['setting_lock_mode'] = 'Lock generation mode';
$string['setting_lock_mode_desc'] = 'Prevent users from overriding the default generation mode on a per-run basis.';
$string['setting_max_corpus_tokens'] = 'Maximum corpus tokens per job';
$string['setting_max_corpus_tokens_desc'] = 'Approximate upper bound on the total extracted corpus size per job (roughly four characters per token). Extraction fails if exceeded.';
$string['setting_max_source_bytes'] = 'Maximum source upload per job';
$string['setting_max_source_bytes_desc'] = 'Maximum total size, in bytes, of the source files uploaded for a single job.';
$string['setting_period_days'] = 'Spend period (days)';
$string['setting_period_days_desc'] = 'Length of the rolling window over which generation spend and image counts are totalled against their caps. Spend older than this ages out of the total. Defaults to 30 days.';
$string['setting_provider_default'] = 'AI Providers default';
$string['setting_provider_image'] = 'Image provider';
$string['setting_provider_image_desc'] = 'Provider for diagram and illustration generation (the generate_image action). Independent of the text provider.';
$string['setting_reasoning_budget_tokens'] = 'Reasoning working budget (tokens)';
$string['setting_reasoning_budget_tokens_desc'] = 'Approximate token budget for a single reasoning call. When a job\'s corpus exceeds this, the corpus is summarized in chunks before the blueprint is synthesised (map-reduce). Distinct from the per-job ingestion cap.';
$string['setting_warn_threshold_pct'] = 'Warning threshold (%)';
$string['setting_warn_threshold_pct_desc'] = 'Percentage of the spend cap at which a soft warning is shown before the hard cap is reached.';
$string['status_approved'] = 'Approved (ready to build)';
$string['status_assessing'] = 'Generating assessments';
$string['status_awaiting_review'] = 'Awaiting review';
$string['status_blueprinted'] = 'Blueprinted';
$string['status_complete'] = 'Complete';
$string['status_extracted'] = 'Sources extracted';
$string['status_extracting'] = 'Extracting sources';
$string['status_failed'] = 'Failed';
$string['status_materializing'] = 'Building course';
$string['task_extractcorpus'] = 'Extract source corpus for course generation';
$string['task_generateblueprint'] = 'Generate course blueprint';
$string['task_materializecourse'] = 'Materialize the generated course';
