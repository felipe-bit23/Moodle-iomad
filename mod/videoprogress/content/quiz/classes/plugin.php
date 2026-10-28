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
 * plugin.php
 *
 * @package   videoprogresscontent_quiz
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresscontent_quiz;

use coding_exception;
use context;
use context_module;
use mod_videoprogress\content\plugin_base;
use MoodleQuickForm;
use stdClass;

/**
 * Implements mandatory synchronized quizzes with server-side answer validation.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized name exposed to Moodle or the plugin interface.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresscontent_quiz");
    }

    /**
     * Adds the subplugin-specific configuration fields to the Moodle form.
     *
     * @param MoodleQuickForm $mform Moodle form instance receiving additional fields.
     * @return void This method does not return a value.
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        global $PAGE;
        $mform->addElement("editor", "question_editor", get_string("question", "videoprogresscontent_quiz"), null,
            self::editor_options($PAGE->context));
        $mform->addRule("question_editor", null, "required", null, "client");
        for ($index = 0; $index < 4; $index++) {
            $mform->addElement("text", "answer{$index}",
                get_string("answer", "videoprogresscontent_quiz", $index + 1), ["size" => 70]);
            $mform->setType("answer{$index}", PARAM_TEXT);
        }
        $mform->addElement("select", "correctanswer", get_string("correctanswer", "videoprogresscontent_quiz"), [
            0 => get_string("answer", "videoprogresscontent_quiz", 1),
            1 => get_string("answer", "videoprogresscontent_quiz", 2),
            2 => get_string("answer", "videoprogresscontent_quiz", 3),
            3 => get_string("answer", "videoprogresscontent_quiz", 4),
        ]);
        $mform->addElement("text", "correctfeedback", get_string("correctfeedback", "videoprogresscontent_quiz"), ["size" => 70]);
        $mform->setType("correctfeedback", PARAM_TEXT);
        $mform->addElement("text", "incorrectfeedback",
            get_string("incorrectfeedback", "videoprogresscontent_quiz"), ["size" => 70]);
        $mform->setType("incorrectfeedback", PARAM_TEXT);
    }

    /**
     * Prepares stored configuration and draft file areas for the edit form.
     *
     * @param stdClass $data Validated input or tracking data.
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     */
    public function prepare_form_data(stdClass $data, stdClass|null $item, context_module $context): stdClass {
        $config = $this->decode_config($item);
        $data->question = $config["question"] ?? '';
        $data->questionformat = $config["questionformat"] ?? FORMAT_HTML;
        foreach (range(0, 3) as $index) {
            $data->{"answer{$index}"} = $config["answers"][$index] ?? '';
        }
        $data->correctanswer = (int)($config["correctanswer"] ?? 0);
        $data->correctfeedback = $config["correctfeedback"] ?? '';
        $data->incorrectfeedback = $config["incorrectfeedback"] ?? '';
        return file_prepare_standard_editor($data, "question", self::editor_options($context), $context,
            "videoprogresscontent_quiz", "question", $item->id ?? 0);
    }

    /**
     * Performs server-side validation for submitted Moodle form values.
     *
     * @param array $data Validated input or tracking data.
     * @param array $files Files submitted with the Moodle form.
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function validation(array $data, array $files, stdClass|null $item, context_module $context): array {
        $answers = array_map(static fn(int $index): string => trim((string)($data["answer{$index}"] ?? '')), range(0, 3));
        $errors = [];
        if (trim((string)($data["question_editor"]["text"] ?? '')) === '') {
            $errors["question_editor"] = get_string("required");
        }
        if (count(array_filter($answers, static fn(string $answer): bool => $answer !== '')) < 2) {
            $errors["answer0"] = get_string("minimumanswers", "videoprogresscontent_quiz");
        }
        $correct = (int)($data["correctanswer"] ?? -1);
        if (!isset($answers[$correct]) || $answers[$correct] === '') {
            $errors["correctanswer"] = get_string("invalidcorrectanswer", "videoprogresscontent_quiz");
        }
        return $errors;
    }

    /**
     * Persists this interaction type configuration and moves embedded files into protected storage.
     *
     * @param stdClass $item Synchronized content item record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    public function save(stdClass $item, stdClass $data, context_module $context): array {
        $data = file_postupdate_standard_editor($data, "question", self::editor_options($context), $context,
            "videoprogresscontent_quiz", "question", $item->id);
        $answers = [];
        foreach (range(0, 3) as $index) {
            $answers[$index] = trim((string)$data->{"answer{$index}"});
        }
        return [
            "question" => $data->question,
            "questionformat" => $data->questionformat,
            "answers" => $answers,
            "correctanswer" => (int)$data->correctanswer,
            "correctfeedback" => trim((string)$data->correctfeedback),
            "incorrectfeedback" => trim((string)$data->incorrectfeedback),
        ];
    }

    /**
     * Deletes embedded files owned by this synchronized content item.
     *
     * @param stdClass $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public function delete(stdClass $item, context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "videoprogresscontent_quiz", "question", $item->id);
    }

    /**
     * Indicates whether the content item must be completed before playback may continue.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public function is_required(array $config): bool {
        return true;
    }

    /**
     * Indicates whether the content item pauses playback while it is displayed.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public function pauses_video(array $config): bool {
        return true;
    }

    /**
     * Returns the Frankenstyle name of the AMD module that controls this content type.
     *
     * @return string The resolved or formatted string value.
     */
    public function get_amd_module(): string {
        return 'videoprogresscontent_quiz/quiz';
    }

    /**
     * Returns the sanitized configuration that may be exposed to the browser.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return array Structured data produced by the operation.
     */
    public function get_client_data(array $config): array {
        return [];
    }

    /**
     * Renders the synchronized player overlay through the subplugin Mustache template.
     *
     * @param stdClass $item Synchronized content item record.
     * @param stdClass $point Video timeline point record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function render_overlay(stdClass $item, stdClass $point, context_module $context): string {
        global $OUTPUT;
        $config = $this->decode_config($item);
        $question = file_rewrite_pluginfile_urls($config["question"] ?? '', 'pluginfile.php', $context->id,
            "videoprogresscontent_quiz", "question", $item->id);
        $answers = [];
        foreach ($config["answers"] ?? [] as $index => $answer) {
            if (trim((string)$answer) !== '') {
                $answers[] = ["index" => (int)$index, "number" => (int)$index + 1, "text" => format_string($answer)];
            }
        }
        return $OUTPUT->render_from_template('videoprogresscontent_quiz/overlay', [
            "itemid" => $item->id,
            "pointtitle" => format_string($point->title),
            "question" => format_text($question, $config["questionformat"] ?? FORMAT_HTML, ["context" => $context]),
            "answers" => $answers,
        ]);
    }

    /**
     * Validates a student interaction response and returns completion and feedback.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @param array $response Decoded student response.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function validate_response(array $config, array $response): array {
        $answer = isset($response["answer"]) && is_int($response["answer"]) ? $response["answer"] : -1;
        $completed = $answer >= 0 && $answer === (int)($config["correctanswer"] ?? -2) &&
            trim((string)($config["answers"][$answer] ?? '')) !== '';
        $feedback = $completed ? trim((string)($config["correctfeedback"] ?? ''))
            : trim((string)($config["incorrectfeedback"] ?? ''));
        if ($feedback === '') {
            $feedback = get_string($completed ? "defaultcorrectfeedback" : "defaultincorrectfeedback",
                "videoprogresscontent_quiz");
        }
        return ["completed" => $completed, "feedback" => $feedback];
    }

    /**
     * Returns the secure Moodle editor and embedded-file configuration for this subplugin.
     *
     * @param context $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    private static function editor_options(context $context): array {
        return ["context" => $context, "maxfiles" => 10, "maxbytes" => 0, "subdirs" => 1, "trusttext" => false];
    }
}
