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
 * Operator-controlled course depth: audience level and length/depth (D26).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * The single tuning seam for the two create-time depth controls (DECISIONS D26).
 *
 * Two independent, named axes steer the blueprint prompt:
 *   - Audience level (beginner | intermediate | advanced) sets the pitch and the
 *     Bloom's level the objectives are phrased at.
 *   - Length/depth (brief | standard | comprehensive) sets an approximate section
 *     RANGE and reading/assessment heft.
 *
 * All numbers and prose live here so the controls can be tuned in one place and
 * unit-tested in isolation. The blueprint IR schema is unaffected — this only
 * produces prompt guidance and the stored job params (no new JSON fields).
 */
class course_depth {
    /** @var string Audience: assume no prior knowledge. */
    public const LEVEL_BEGINNER = 'beginner';

    /** @var string Audience: assume a foundational grounding. */
    public const LEVEL_INTERMEDIATE = 'intermediate';

    /** @var string Audience: assume working expertise. */
    public const LEVEL_ADVANCED = 'advanced';

    /** @var string Depth: few sections, concise. */
    public const DEPTH_BRIEF = 'brief';

    /** @var string Depth: moderate breadth. */
    public const DEPTH_STANDARD = 'standard';

    /** @var string Depth: many sections, thorough. */
    public const DEPTH_COMPREHENSIVE = 'comprehensive';

    /** @var string Site/house default audience level (mirrors install.xml + settings). */
    public const DEFAULT_LEVEL = self::LEVEL_INTERMEDIATE;

    /** @var string Site/house default depth. */
    public const DEFAULT_DEPTH = self::DEPTH_STANDARD;

    /** @var string[] Valid audience levels, for normalization without get_string(). */
    private const VALID_LEVELS = [self::LEVEL_BEGINNER, self::LEVEL_INTERMEDIATE, self::LEVEL_ADVANCED];

    /** @var string[] Valid depths, for normalization without get_string(). */
    private const VALID_DEPTHS = [self::DEPTH_BRIEF, self::DEPTH_STANDARD, self::DEPTH_COMPREHENSIVE];

    /**
     * Section-range and reading targets per depth. Ranges are non-overlapping so
     * the controls demonstrably move the output (DECISIONS D26-a).
     *
     * @var array<string,array{min:int,max:int,reading:string}>
     */
    private const DEPTH_SPECS = [
        self::DEPTH_BRIEF => [
            'min' => 3,
            'max' => 4,
            'reading' => 'concise readings and lighter assessment',
            'shape' => 'Keep it tight: cover only the essential topics, merging related ideas '
                . 'into the same section rather than splitting them out.',
        ],
        self::DEPTH_STANDARD => [
            'min' => 5,
            'max' => 7,
            'reading' => 'moderate-depth readings',
            'shape' => 'Give the main topics their own sections at a balanced grain.',
        ],
        self::DEPTH_COMPREHENSIVE => [
            'min' => 8,
            'max' => 12,
            'reading' => 'thorough readings that cover subtopics in depth, with fuller assessment',
            'shape' => 'Be exhaustive: break the material into fine-grained sections, giving '
                . 'distinct subtopics, examples, and edge cases their own sections to reach this count.',
        ],
    ];

    /**
     * Pitch and Bloom's framing per audience level. The audience axis influences
     * question difficulty through the Bloom's framing, not a question-count knob —
     * DEFAULT_QUESTION_COUNT is untouched (DECISIONS D26-a).
     *
     * @var array<string,array{assume:string,bloom:string}>
     */
    private const LEVEL_SPECS = [
        self::LEVEL_BEGINNER => [
            'assume' => 'Assume no prior knowledge. Define all terms on first use, use plain '
                . 'language, and ground ideas in concrete, everyday examples.',
            'bloom' => 'Phrase every learning objective at the Remember and Understand levels — '
                . 'use verbs like recall, describe, identify, and explain, and avoid higher-order '
                . 'verbs like evaluate or critique.',
            'reading' => 'Write for a complete beginner who knows nothing about this topic: '
                . 'define every term the first time it appears, use plain everyday language and '
                . 'short sentences, and explain each idea with a concrete, relatable example. '
                . 'Do not assume any background.',
        ],
        self::LEVEL_INTERMEDIATE => [
            'assume' => 'Assume a foundational grounding in the subject. Use domain vocabulary '
                . 'with brief reminders where it helps.',
            'bloom' => 'Phrase every learning objective at the Apply and Analyze levels — '
                . 'use verbs like apply, compare, differentiate, and analyze.',
            'reading' => 'Write for a learner with a foundational grounding in this subject: '
                . 'use domain vocabulary normally, adding a brief reminder only for less common '
                . 'terms, and keep a practical, applied tone that builds on assumed basics.',
        ],
        self::LEVEL_ADVANCED => [
            'assume' => 'Assume working expertise. Be concise and nuanced, and surface '
                . 'tradeoffs and edge cases.',
            'bloom' => 'Phrase every learning objective at the Analyze and Evaluate levels — '
                . 'use verbs like evaluate, critique, justify, and assess, and avoid lower-order '
                . 'verbs like recall or describe.',
            'reading' => 'Write for a reader with working expertise: be concise and technical, '
                . 'skip basic definitions, and focus on tradeoffs, edge cases, and nuance rather '
                . 'than fundamentals.',
        ],
    ];

    /**
     * Audience-level options for the create form and admin setting.
     *
     * @return array<string,string> value => localized label
     */
    public static function levels(): array {
        return [
            self::LEVEL_BEGINNER => get_string('level_beginner', 'local_coursegen'),
            self::LEVEL_INTERMEDIATE => get_string('level_intermediate', 'local_coursegen'),
            self::LEVEL_ADVANCED => get_string('level_advanced', 'local_coursegen'),
        ];
    }

    /**
     * Depth options for the create form and admin setting.
     *
     * @return array<string,string> value => localized label
     */
    public static function depths(): array {
        return [
            self::DEPTH_BRIEF => get_string('depth_brief', 'local_coursegen'),
            self::DEPTH_STANDARD => get_string('depth_standard', 'local_coursegen'),
            self::DEPTH_COMPREHENSIVE => get_string('depth_comprehensive', 'local_coursegen'),
        ];
    }

    /**
     * Clamp an audience level to a known value, falling back to the default.
     *
     * @param string|null $level The candidate value.
     * @return string A valid level.
     */
    public static function normalize_level(?string $level): string {
        return in_array($level, self::VALID_LEVELS, true) ? $level : self::DEFAULT_LEVEL;
    }

    /**
     * Clamp a depth to a known value, falling back to the default.
     *
     * @param string|null $depth The candidate value.
     * @return string A valid depth.
     */
    public static function normalize_depth(?string $depth): string {
        return in_array($depth, self::VALID_DEPTHS, true) ? $depth : self::DEFAULT_DEPTH;
    }

    /**
     * Resolve the concrete targets for a (level, depth) pair.
     *
     * @param string|null $level Audience level (normalized).
     * @param string|null $depth Length/depth (normalized).
     * @return array{level:string,depth:string,sectionmin:int,sectionmax:int,fragment:string}
     */
    public static function profile(?string $level, ?string $depth): array {
        $level = self::normalize_level($level);
        $depth = self::normalize_depth($depth);
        $depthspec = self::DEPTH_SPECS[$depth];
        $levelspec = self::LEVEL_SPECS[$level];
        return [
            'level' => $level,
            'depth' => $depth,
            'sectionmin' => $depthspec['min'],
            'sectionmax' => $depthspec['max'],
            'fragment' => self::build_fragment($depthspec, $levelspec),
        ];
    }

    /**
     * The prompt fragment for a (level, depth) pair — woven into the blueprint
     * and per-section regeneration prompts so a regenerated section matches.
     *
     * @param string|null $level Audience level.
     * @param string|null $depth Length/depth.
     * @return string
     */
    public static function prompt_fragment(?string $level, ?string $depth): string {
        return self::profile($level, $depth)['fragment'];
    }

    /**
     * The reading-pitch instruction for an audience level, threaded into the
     * per-section reading-content prompt (D26 Fix 2). This is where the audience
     * axis actually bites — vocabulary, assumed knowledge, and how concepts are
     * explained — rather than as objective-verb framing in the blueprint prompt.
     *
     * @param string|null $level Audience level.
     * @return string
     */
    public static function reading_pitch(?string $level): string {
        return self::LEVEL_SPECS[self::normalize_level($level)]['reading'];
    }

    /**
     * Assemble the design-targets block from the depth and level specs.
     *
     * @param array{min:int,max:int,reading:string} $depthspec
     * @param array{assume:string,bloom:string} $levelspec
     * @return string
     */
    private static function build_fragment(array $depthspec, array $levelspec): string {
        $min = $depthspec['min'];
        $max = $depthspec['max'];
        $reading = $depthspec['reading'];
        $shape = $depthspec['shape'];
        $assume = $levelspec['assume'];
        $bloom = $levelspec['bloom'];
        return <<<FRAGMENT
COURSE DESIGN TARGETS — firm requirements that take priority over the source's own
structure (split or merge the source topics as needed to satisfy them):
1. SECTION COUNT: regardless of how many topics the source contains, the "sections"
   array must hold approximately {$min}-{$max} sections (no fewer than {$min}, no more
   than {$max}). {$shape} Use {$reading}.
2. AUDIENCE: {$assume}
3. OBJECTIVES: {$bloom}
FRAGMENT;
    }
}
