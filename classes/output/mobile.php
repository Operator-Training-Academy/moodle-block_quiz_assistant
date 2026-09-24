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
 * Moodle App output for Quiz assistant.
 *
 * @package    block_quiz_assistant
 * @copyright  2026 Operator Training Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quiz_assistant\output;

/**
 * Provides the Quiz assistant course view in the Moodle App.
 */
class mobile {
    /**
     * Render the current course's quiz summary for the Moodle App.
     *
     * @param array $args Handler arguments supplied by the Moodle App.
     * @return array Remote add-on content.
     */
    public static function mobile_course_view(array $args): array {
        global $DB, $OUTPUT;

        $course = get_course((int) $args['courseid']);
        $context = \context_course::instance($course->id);
        require_capability('block/quiz_assistant:view', $context);

        $quizzes = array_values(get_all_instances_in_course('quiz', $course, null, true) ?: []);
        $quizids = array_map(static fn(\stdClass $quiz): int => (int) $quiz->id, $quizzes);
        $questioncounts = [];
        $gradepasses = [];
        $overridesbyquiz = [];

        if ($quizids) {
            [$quizsql, $quizparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quiz');
            $questioncounts = $DB->get_records_sql_menu(
                "SELECT quizid, COUNT(*) FROM {quiz_slots} WHERE quizid {$quizsql} GROUP BY quizid",
                $quizparams,
            );
            $gradeitems = $DB->get_records_select(
                'grade_items',
                "courseid = :courseid AND itemtype = :itemtype AND itemmodule = :itemmodule
                 AND itemnumber = 0 AND iteminstance {$quizsql}",
                ['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz'] + $quizparams,
            );
            foreach ($gradeitems as $gradeitem) {
                $gradepasses[$gradeitem->iteminstance] = $gradeitem;
            }
            $overrides = $DB->get_records_sql(
                "SELECT qo.quiz, qo.groupid, qo.password, g.name AS groupname, u.firstname, u.lastname
                   FROM {quiz_overrides} qo
              LEFT JOIN {groups} g ON g.id = qo.groupid
              LEFT JOIN {user} u ON u.id = qo.userid
                  WHERE qo.quiz {$quizsql} AND qo.password IS NOT NULL AND qo.password <> ''
               ORDER BY qo.quiz, qo.id",
                $quizparams,
            );
            foreach ($overrides as $override) {
                $overridesbyquiz[$override->quiz][] = [
                    'target' => $override->groupid ?
                        get_string('group', 'block_quiz_assistant') . ': ' . format_string($override->groupname) :
                        get_string('user', 'block_quiz_assistant') . ': ' . fullname($override),
                    'password' => $override->password,
                ];
            }
        }

        $data = [
            'quizzes' => [],
            'noquizzes' => get_string('noquizzes', 'block_quiz_assistant'),
        ];
        foreach ($quizzes as $quiz) {
            $modulecontext = \context_module::instance($quiz->coursemodule);
            $data['quizzes'][] = [
                'name' => format_string($quiz->name, true, ['context' => $modulecontext]),
                'url' => (new \moodle_url('/mod/quiz/view.php', ['id' => $quiz->coursemodule]))->out(false),
                'intro' => format_module_intro('quiz', $quiz, $quiz->coursemodule, false),
                'password' => $quiz->password,
                'passwordlabel' => get_string('defaultpassword', 'block_quiz_assistant'),
                'overrides' => $overridesbyquiz[$quiz->id] ?? [],
                'details' => [
                    [
                        'label' => get_string('timelimit', 'block_quiz_assistant'),
                        'value' => $quiz->timelimit ? format_time($quiz->timelimit) :
                            get_string('notimelimit', 'block_quiz_assistant'),
                    ],
                    [
                        'label' => get_string('attempts', 'block_quiz_assistant'),
                        'value' => $quiz->attempts ?: get_string('unlimited', 'block_quiz_assistant'),
                    ],
                    [
                        'label' => get_string('gradepass', 'block_quiz_assistant'),
                        'value' => !empty($gradepasses[$quiz->id]->gradepass) ?
                            format_float($gradepasses[$quiz->id]->gradepass, 2) :
                            get_string('notset', 'block_quiz_assistant'),
                    ],
                    [
                        'label' => get_string('questions', 'block_quiz_assistant'),
                        'value' => $questioncounts[$quiz->id] ?? 0,
                    ],
                ],
            ];
        }

        return [
            'templates' => [[
                'id' => 'main',
                'html' => $OUTPUT->render_from_template('block_quiz_assistant/mobile', $data),
            ]],
        ];
    }
}
