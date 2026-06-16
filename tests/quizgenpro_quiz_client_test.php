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
 * Drift guard for the local_quizgenpro question-field coupling.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\quizgenpro_quiz_client;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks the one field-name coupling to local_quizgenpro: its generator emits
 * "question", its exporter reads "text", and we bridge them. If either field
 * drifts, this test fails — not production.
 */
#[CoversClass(\local_coursegen\local\ai\quizgenpro_quiz_client::class)]
final class quizgenpro_quiz_client_test extends \advanced_testcase {
    /**
     * A generator-shaped question survives the remap and the real exporter with
     * its text intact.
     *
     * @return void
     */
    public function test_generator_to_exporter_field_contract(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertTrue(
            class_exists('\local_quizgenpro\generator'),
            'local_quizgenpro generator missing — the assessment delegate is gone.'
        );
        $this->assertTrue(
            class_exists('\local_quizgenpro\exporter'),
            'local_quizgenpro exporter missing — the assessment delegate is gone.'
        );

        $course = $this->getDataGenerator()->create_course();
        $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
        $bankcontext = \context_module::instance($bankcm->id);

        // A question in local_quizgenpro's GENERATOR output shape (field "question").
        $generatorshaped = [[
            'type' => 'truefalse',
            'question' => 'The Earth orbits the Sun.',
            'correctanswer' => 'true',
            'explanation' => 'Heliocentric model.',
        ]];

        // The adapter remaps it to the EXPORTER's shape (field "text").
        $mapped = quizgenpro_quiz_client::map_questions($generatorshaped);
        $this->assertSame('The Earth orbits the Sun.', $mapped[0]->text);

        // The REAL exporter must accept that shape and persist the question text.
        $result = (new \local_quizgenpro\exporter())->export_to_question_bank(
            json_encode($mapped),
            (int) $course->id,
            (int) $bankcontext->id
        );
        $this->assertEquals(1, $result['count']);

        $text = $DB->get_field_sql(
            'SELECT q.questiontext
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qbe.questioncategoryid = :catid',
            ['catid' => $result['catid']]
        );
        $this->assertSame(
            'The Earth orbits the Sun.',
            $text,
            'quizgenpro question-field shape drifted: the remap or the exporter input changed.'
        );
    }
}
