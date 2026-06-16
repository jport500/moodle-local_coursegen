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
 * local_quizgenpro implementation of the quiz client.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Delegates question generation to local_quizgenpro's generator and maps its
 * output into the shape its exporter expects (the one field its UI remaps,
 * "question" → "text"). Question generation is therefore never reimplemented
 * here (D5/D10). quizgenpro uses its own AI provider config and exposes no
 * token/cost, so quiz spend is out of coursegen's cap (DECISIONS D13).
 */
class quizgenpro_quiz_client implements quiz_client {
    /**
     * Generate questions via local_quizgenpro.
     *
     * @param string $content The section reading content.
     * @param int $count Desired number of questions.
     * @param \context $context The course context.
     * @return \stdClass[] Exporter-ready question objects (empty on failure).
     */
    public function generate_questions(string $content, int $count, \context $context): array {
        if (!class_exists('\local_quizgenpro\generator')) {
            return [];
        }
        try {
            $generator = new \local_quizgenpro\generator($context->id);
            $raw = $generator->generate($content, $count, 'mixed', 'medium');
        } catch (\coding_exception $e) {
            throw $e; // Never mask a programmer error as a skippable quiz failure.
        } catch (\moodle_exception $e) {
            return []; // AI/generation failure — caller skips the quiz and builds on.
        }

        return self::map_questions((array) $raw);
    }

    /**
     * Map local_quizgenpro generator output into the shape its exporter expects.
     *
     * This is the one field-name coupling between quizgenpro's generator (which
     * emits "question") and its exporter (which reads "text") — quizgenpro's own
     * UI does the same remap client-side. Extracted so a drift in either field
     * breaks a test rather than production.
     *
     * @param array $raw The generator's questions (assoc arrays or objects).
     * @return \stdClass[] Exporter-ready question objects (empty texts dropped).
     */
    public static function map_questions(array $raw): array {
        $questions = [];
        foreach ($raw as $q) {
            $q = (array) $q;
            $text = trim((string) ($q['question'] ?? $q['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $questions[] = (object) [
                'text' => $text,
                'type' => ($q['type'] ?? 'truefalse') === 'multichoice' ? 'multichoice' : 'truefalse',
                'options' => array_values((array) ($q['options'] ?? [])),
                'correctanswer' => $q['correctanswer'] ?? 'true',
                'explanation' => (string) ($q['explanation'] ?? ''),
            ];
        }
        return $questions;
    }
}
