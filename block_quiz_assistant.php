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
 * Course quiz details block.
 *
 * @package    block_quiz_assistant
 * @copyright  2026 Operator Training Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_quiz\quiz_settings;

/**
 * Course quiz details block.
 *
 * @package    block_quiz_assistant
 * @copyright  2026 Operator Training Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_quiz_assistant extends block_base {
    /**
     * Initialise the block title.
     *
     * @return void
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_quiz_assistant');
    }

    /**
     * Limit the block to course pages.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'all' => false,
            'course-view' => true,
        ];
    }

    /**
     * One course summary is sufficient for each course page.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Require the block-specific edit capability in addition to Moodle's normal block edit permission.
     *
     * @return bool
     */
    public function user_can_edit(): bool {
        return parent::user_can_edit() && has_capability('block/quiz_assistant:edit', $this->context);
    }

    /**
     * Build the current course's quiz summary.
     *
     * @return stdClass
     */
    public function get_content(): stdClass {
        global $COURSE, $DB;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = (object) [
            'text' => '',
            'footer' => '',
        ];

        $course = $this->page->course ?? $COURSE ?? null;
        if (empty($course) || empty($course->id) || $course->id == SITEID) {
            if ($this->page->user_is_editing()) {
                $this->content->text = html_writer::div(
                    get_string('notincourse', 'block_quiz_assistant'),
                    'alert alert-info',
                );
            }
            return $this->content;
        }

        $coursecontext = context_course::instance($course->id);
        if (!has_capability('block/quiz_assistant:view', $coursecontext)) {
            if ($this->page->user_is_editing()) {
                $this->content->text = html_writer::div(
                    get_string('nopermission', 'block_quiz_assistant'),
                    'alert alert-warning',
                );
            }
            return $this->content;
        }

        try {
            $quizzes = get_all_instances_in_course('quiz', $course, null, true);
        } catch (\Throwable $e) {
            $quizzes = [];
        }

        if (!$quizzes) {
            $this->content->text = html_writer::div(get_string('noquizzes', 'block_quiz_assistant'));
            return $this->content;
        }

        $quizids = array_map(static fn(stdClass $quiz): int => (int) $quiz->id, $quizzes);
        [$quizsql, $quizparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quiz');

        $questioncounts = $DB->get_records_sql_menu(
            "SELECT quizid, COUNT(*)
               FROM {quiz_slots}
              WHERE quizid {$quizsql}
           GROUP BY quizid",
            $quizparams,
        );

        $gradeitems = $DB->get_records_select(
            'grade_items',
            "courseid = :courseid AND itemtype = :itemtype AND itemmodule = :itemmodule
             AND itemnumber = 0 AND iteminstance {$quizsql}",
            ['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'quiz'] + $quizparams,
        );
        $gradepasses = [];
        foreach ($gradeitems as $gradeitem) {
            $gradepasses[$gradeitem->iteminstance] = $gradeitem;
        }

        $overrides = $DB->get_records_sql(
            "SELECT qo.id, qo.quiz, qo.groupid, qo.userid, qo.password, g.name AS groupname,
                    u.firstname, u.lastname
               FROM {quiz_overrides} qo
          LEFT JOIN {groups} g ON g.id = qo.groupid
          LEFT JOIN {user} u ON u.id = qo.userid
              WHERE qo.quiz {$quizsql} AND qo.password IS NOT NULL AND qo.password <> ''
           ORDER BY qo.quiz, qo.id",
            $quizparams,
        );
        $overridesbyquiz = [];
        foreach ($overrides as $override) {
            $overridesbyquiz[$override->quiz][] = $override;
        }

        foreach ($quizzes as $quiz) {
            try {
                $modulecontext = context_module::instance($quiz->coursemodule);
                $summary = html_writer::tag(
                    'h4',
                    html_writer::link(
                        new moodle_url('/mod/quiz/view.php', ['id' => $quiz->coursemodule]),
                        format_string($quiz->name, true, ['context' => $modulecontext]),
                    ),
                    ['class' => 'h5'],
                );

                $summary .= format_module_intro('quiz', $quiz, $quiz->coursemodule, false);

                $timelimit = $quiz->timelimit ? format_time($quiz->timelimit) :
                    get_string('notimelimit', 'block_quiz_assistant');
                $attempts = $quiz->attempts ?: get_string('unlimited', 'block_quiz_assistant');
                $gradepass = get_string('notset', 'block_quiz_assistant');
                if (!empty($gradepasses[$quiz->id]->gradepass)) {
                    $gradepass = format_float($gradepasses[$quiz->id]->gradepass, 2);
                }
                $questions = $questioncounts[$quiz->id] ?? 0;

                $details = [
                    get_string('timelimit', 'block_quiz_assistant') => $timelimit,
                    get_string('attempts', 'block_quiz_assistant') => $attempts,
                    get_string('gradepass', 'block_quiz_assistant') => $gradepass,
                    get_string('questions', 'block_quiz_assistant') => $questions,
                ];

                $detailhtml = '';
                foreach ($details as $label => $value) {
                    $detailhtml .= html_writer::tag('dt', s($label));
                    $detailhtml .= html_writer::tag('dd', $value);
                }
                $summary .= html_writer::tag('dl', $detailhtml, ['class' => 'mb-2']);

                if ($quiz->password !== '') {
                    $summary .= html_writer::tag('strong', get_string('defaultpassword', 'block_quiz_assistant'));
                    $summary .= $this->render_password_input('quiz_pass_' . $quiz->id, $quiz->password);
                }

                if (!empty($overridesbyquiz[$quiz->id])) {
                    $summary .= html_writer::tag('strong', get_string('overrides', 'block_quiz_assistant'));
                    $overrideitems = '';
                    foreach ($overridesbyquiz[$quiz->id] as $idx => $override) {
                        if ($override->groupid) {
                            $target = get_string('group', 'block_quiz_assistant') . ': ' .
                                format_string($override->groupname);
                        } else {
                            $target = get_string('user', 'block_quiz_assistant') . ': ' . fullname($override);
                        }
                        $inputhtml = $this->render_password_input(
                            'override_pass_' . $quiz->id . '_' . $idx,
                            $override->password,
                        );
                        $overrideitems .= html_writer::tag(
                            'li',
                            html_writer::tag('div', s($target), ['class' => 'small text-muted mb-1']) . $inputhtml,
                            ['class' => 'mb-2'],
                        );
                    }
                    $summary .= html_writer::tag('ul', $overrideitems, ['class' => 'list-unstyled mb-2']);
                }

                if (class_exists('mod_quiz\quiz_settings')) {
                    $quizsettings = quiz_settings::create($quiz->id);
                    $accessrules = $quizsettings->get_access_manager(time())->describe_rules();
                    if ($accessrules) {
                        $filteredrules = [];
                        $skipregex = '/(time limit|attempts allowed|need to know the quiz password|password)/i';
                        foreach ($accessrules as $rule) {
                            if (preg_match($skipregex, strip_tags($rule))) {
                                continue;
                            }
                            $filteredrules[] = $rule;
                        }
                        if (!empty($filteredrules)) {
                            $ruleitems = '';
                            foreach ($filteredrules as $rule) {
                                $ruleitems .= html_writer::tag('li', $rule);
                            }
                            $summary .= html_writer::tag('strong', get_string('testinfo', 'block_quiz_assistant'));
                            $summary .= html_writer::tag('ul', $ruleitems, ['class' => 'mb-2']);
                        }
                    }
                }

                if (has_capability('mod/quiz:viewreports', $modulecontext)) {
                    $summary .= html_writer::link(
                        new moodle_url('/mod/quiz/report.php', ['id' => $quiz->coursemodule]),
                        get_string('viewresults', 'block_quiz_assistant'),
                        ['class' => 'btn btn-secondary btn-sm'],
                    );
                }

                $this->content->text .= html_writer::div($summary, 'mb-4');
            } catch (\Throwable $e) {
                if ($this->page->user_is_editing()) {
                    $this->content->text .= html_writer::div(
                        s($e->getMessage()),
                        'alert alert-danger mb-2',
                    );
                }
            }
        }

        if (empty($this->content->text)) {
            $this->content->text = html_writer::div(get_string('noquizzes', 'block_quiz_assistant'));
        }

        return $this->content;
    }

    /**
     * Render a password input box with a show/hide toggle button.
     *
     * @param string $id Unique element ID suffix.
     * @param string $password Password string.
     * @return string HTML output.
     */
    protected function render_password_input(string $id, string $password): string {
        $showstr = get_string('showpassword', 'block_quiz_assistant');
        $hidestr = get_string('hidepassword', 'block_quiz_assistant');

        $input = html_writer::empty_tag('input', [
            'type' => 'password',
            'id' => $id,
            'class' => 'form-control font-monospace',
            'value' => $password,
            'readonly' => 'readonly',
            'aria-label' => get_string('password', 'block_quiz_assistant'),
        ]);

        $button = html_writer::tag('button', s($showstr), [
            'type' => 'button',
            'class' => 'btn btn-outline-secondary',
            'onclick' => "var input=document.getElementById('" . s($id) . "');" .
                "if(input.type==='password'){input.type='text';this.innerText='" . s($hidestr) . "';}" .
                "else{input.type='password';this.innerText='" . s($showstr) . "';}",
        ]);

        return html_writer::div($input . $button, 'input-group mb-2');
    }
}
