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
 * AI Providers implementation of the image client.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Routes image generation through core_ai's generate_image action. This is a
 * distinct action from text generation, routed to image-capable providers
 * independently (DECISIONS D11). The response returns the image as a draft
 * stored_file.
 */
class core_ai_image_client implements image_client {
    /** @var string The image-generation action class. */
    private const ACTION = 'core_ai\\aiactions\\generate_image';

    /**
     * Generate an image via the AI Providers subsystem.
     *
     * @param string $prompt The image prompt.
     * @param \context $context The context the request is made in.
     * @param int $userid The user the request is attributed to.
     * @return image_result
     */
    public function generate_image(
        string $prompt,
        \context $context,
        int $userid,
        string $aspectratio = 'square'
    ): image_result {
        $manager = \core\di::get(\core_ai\manager::class);
        $providername = $this->resolve_provider_name($manager);

        // The provider's calculate_size accepts only these three and throws on
        // anything else, so clamp an unknown value to 'square' (D36).
        if (!in_array($aspectratio, ['square', 'landscape', 'portrait'], true)) {
            $aspectratio = 'square';
        }

        $action = new \core_ai\aiactions\generate_image(
            contextid: $context->id,
            userid: $userid,
            prompttext: $prompt,
            quality: 'standard',
            aspectratio: $aspectratio,
            numimages: 1,
            style: 'vivid',
        );
        $response = $manager->process_action($action);

        if (!$response->get_success()) {
            $error = trim($response->get_error() . ' ' . $response->get_errormessage());
            return new image_result(success: false, provider: $providername, error: $error);
        }

        $data = $response->get_response_data();
        $draftfile = $data['draftfile'] ?? null;
        if (!$draftfile instanceof \stored_file) {
            return new image_result(success: false, provider: $providername, error: 'no image returned');
        }
        return new image_result(
            success: true,
            draftfile: $draftfile,
            model: $response->get_model_used(),
            provider: $providername,
        );
    }

    /**
     * Resolve the provider core_ai will use for image generation.
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
