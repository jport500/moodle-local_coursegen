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
 * AI Providers implementation of the text client.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Routes a text-generation call through Moodle's AI Providers subsystem,
 * mirroring the local_quizgenpro house pattern.
 *
 * Note: core_ai's process_action() routes by configured provider order; there
 * is no public API to force a specific provider instance per call in this
 * version, so the resolved provider is reported as the first enabled provider
 * for the action (DECISIONS D5 — documented gap).
 */
class core_ai_text_client implements text_client {
    /** @var string The text-generation action class. */
    private const ACTION = 'core_ai\\aiactions\\generate_text';

    /**
     * Generate text via the AI Providers subsystem.
     *
     * @param string $prompt The full prompt to send.
     * @param \context $context The context the request is made in.
     * @param int $userid The user the request is attributed to.
     * @return text_result
     */
    public function generate(string $prompt, \context $context, int $userid): text_result {
        $manager = \core\di::get(\core_ai\manager::class);
        $providername = $this->resolve_provider_name($manager);

        $action = new \core_ai\aiactions\generate_text(
            contextid: $context->id,
            userid: $userid,
            prompttext: $prompt,
        );
        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            $error = trim($response->get_error() . ' ' . $response->get_errormessage());
            return new text_result(success: false, provider: $providername, error: $error);
        }

        $data = $response->get_response_data();
        return new text_result(
            success: true,
            content: (string) ($data['generatedcontent'] ?? ''),
            model: $response->get_model_used(),
            provider: $providername,
            prompttokens: isset($data['prompttokens']) ? (int) $data['prompttokens'] : null,
            completiontokens: isset($data['completiontokens']) ? (int) $data['completiontokens'] : null,
        );
    }

    /**
     * Resolve the provider core_ai will use (first enabled for the action).
     *
     * @param \core_ai\manager $manager The AI manager.
     * @return string|null The provider name, or null if none enabled.
     */
    private function resolve_provider_name(\core_ai\manager $manager): ?string {
        $providers = $manager->get_providers_for_actions([self::ACTION], true);
        $list = $providers[self::ACTION] ?? [];
        $first = reset($list);
        return $first ? $first->get_name() : null;
    }
}
