<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The main mod_adele configuration form.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_adele\local\path_choice;
use mod_adele\local\teacher_access_mode;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/local/adele/lib.php');

/**
 * Module instance settings form.
 *
 * @package     mod_adele
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_adele_mod_form extends moodleform_mod {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are shown.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('adelename', 'mod_adele'), ['size' => '64']);

        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }

        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'adelename', 'mod_adele');

        // Adding the standard "intro" and "introformat" fields.
        if ($CFG->branch >= 29) {
            $this->standard_intro_elements();
        } else {
            $this->add_intro_editor();
        }

        $mform->addElement('header', 'adelefieldset', get_string('adelefieldset', 'mod_adele'));
        // Adding a link after the header.
        $editorurl = new moodle_url('/local/adele/index.php#/learningpaths/edit');

        $mform->addElement(
            'static',
            'link',
            get_string('mform_options_create_learningpath', 'mod_adele'),
            '<a class ="btn btn-secondary" href="' . $editorurl . '" target="blank">' .
            get_string('mform_options_link_create_learningpath', 'mod_adele') .
            '</a>'
        );

        // The paths the person may edit, and the one an existing activity
        // embeds: it can be kept by whoever may edit the activity, even
        // without editing rights on the path (mod_adele #43). Validation
        // decides on the server what may be set.
        $stored = $this->stored_learningpathid();
        $select = [0 => get_string('noselection', 'form')] + path_choice::options($stored);
        if ($stored && !isset($select[$stored])) {
            // Embedded, but the path is gone: say so, and let a new one be chosen.
            $mform->addElement(
                'static',
                'learningpathmissing',
                '',
                \html_writer::div(get_string('mform_path_missing_stored', 'mod_adele'), 'alert alert-warning')
            );
        }

        $options = [
          'multiple' => false,
          'noselectionstring' => get_string('mform_options_no_selection', 'mod_adele'),
          'tags' => false,
        ];

        $mform->addElement(
            'autocomplete',
            'learningpathid',
            get_string('mform_select_learningpath', 'mod_adele'),
            $select,
            $options
        );
        $mform->setDefault('learningpathid', 0);

        $mform->addRule('learningpathid', get_string('mform_options_required', 'mod_adele'), 'required', null, 'client');

        $views = [
          1 => get_string('mform_options_view_top_level', 'mod_adele'),
          2 => get_string('mform_options_view_floor_level', 'mod_adele'),
        ];
        $mform->addElement('select', 'view', get_string('mform_select_view', 'mod_adele'), $views);

        // Value semantics are unchanged (1 = all participants, 2 = own only);
        // only the display order and default differ, so existing records keep
        // their meaning. "Own results" is listed first and used as default.
        $userlist = [
          2 => get_string('mform_options_userlist_only', 'mod_adele'),
          1 => get_string('mform_options_userlist_all', 'mod_adele'),
        ];
        $mform->addElement('select', 'userlist', get_string('mform_select_userlist', 'mod_adele'), $userlist);
        $mform->setDefault('userlist', 2);

        $participantslist = [
          1 => get_string('mform_options_participantslist_this_course', 'mod_adele'),
          2 => get_string('mform_options_participantslist_starting_courses', 'mod_adele'),
          3 => get_string('mform_options_participantslist_all_courses', 'mod_adele'),
        ];
        $mform->addElement(
            'autocomplete',
            'participantslist',
            get_string('mform_select_participantslist', 'mod_adele'),
            $participantslist,
            ['multiple' => true]
        );

        $mform->addRule('participantslist', get_string('mform_options_required', 'mod_adele'), 'required', null, 'client');

        // This field is only meaningful for the participant options
        // "starting node" and "any node" — the "this course" option always
        // enrols actively into this course. Moodle's declarative hideIf cannot
        // express "hide unless value 2 or 3 is among the selected values" for a
        // multi-select autocomplete, so a small AMD module toggles the field's
        // visibility from the current participantslist selection (see init
        // call below).
        $hostenrolmentmode = [
            'visible' => get_string('mform_options_hostenrolmentmode_visible', 'mod_adele'),
            'hidden' => get_string('mform_options_hostenrolmentmode_hidden', 'mod_adele'),
            'none' => get_string('mform_options_hostenrolmentmode_none', 'mod_adele'),
        ];
        $mform->addElement(
            'select',
            'hostenrolmentmode',
            get_string('mform_select_hostenrolmentmode', 'mod_adele'),
            $hostenrolmentmode
        );
        $mform->addHelpButton('hostenrolmentmode', 'mform_select_hostenrolmentmode', 'mod_adele');
        $mform->setDefault('hostenrolmentmode', 'visible');
        $mform->setType('hostenrolmentmode', PARAM_ALPHA);

        // Show hostenrolmentmode only when participant option 2 ("starting
        // node") or 3 ("any node") is selected.
        global $PAGE;
        $PAGE->requires->js_call_amd(
            'mod_adele/hostenrolmentmode_visibility',
            'init',
            [['2', '3']]
        );

        // Under "More options" of this section (Moodle's "Show more ..."): how
        // teachers reach the node courses they cannot open yet (issue
        // local_adele #600). Offered are the modes the site allows - and the
        // activity's own stored mode, if the site no longer allows it, so that
        // saving the form for another reason does not change it silently.
        // The teacher enrolment modes follow the site's policy (TP4): what the
        // person editing may not choose is locked (policy 1) or not offered.
        global $USER;
        $stored = $this->stored_teachercourseaccessmode();
        $pathid = (int) ($this->current->learningpathid ?? 0);
        $select = $mform->createElement(
            'select',
            'teachercourseaccessmode',
            get_string('teachercourseaccessmode', 'mod_adele')
        );
        foreach (teacher_access_mode::form_options($stored, $pathid, (int) $USER->id, $this->mode_context()) as $mode => $option) {
            $select->addOption($option['label'], $mode, $option['disabled'] ? ['disabled' => 'disabled'] : null);
        }
        $mform->addElement($select);
        $mform->addHelpButton('teachercourseaccessmode', 'teachercourseaccessmode', 'mod_adele');
        $mform->setType('teachercourseaccessmode', PARAM_ALPHAEXT);
        $mform->setDefault('teachercourseaccessmode', $stored ?? teacher_access_mode::site_default());
        if (teacher_access_mode::is_locked($stored, $pathid, (int) $USER->id, $this->mode_context())) {
            $mform->hardFreeze('teachercourseaccessmode');
            $mform->addElement(
                'static',
                'teachercourseaccessmode_locked_hint',
                '',
                \html_writer::div(
                    get_string('teachercourseaccessmode_locked_hint', 'mod_adele'),
                    'alert alert-info mb-0',
                    ['data-testid' => 'teachercourseaccessmode-locked']
                )
            );
        } else if ($stored !== null && !teacher_access_mode::is_allowed($stored)) {
            // Not tucked away: whoever edits the activity is to see it.
            $mform->addElement(
                'static',
                'teachercourseaccessmode_notallowed_hint',
                '',
                \html_writer::div(
                    get_string('teachercourseaccessmode_notallowed_hint', 'mod_adele'),
                    'alert alert-warning mb-0',
                    ['data-testid' => 'teachercourseaccessmode-notallowed']
                )
            );
        } else {
            $mform->setAdvanced('teachercourseaccessmode');
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Server-side validation
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $reason = path_choice::check((int) ($data['learningpathid'] ?? 0), $this->stored_learningpathid());
        if ($reason !== path_choice::OK) {
            $errors['learningpathid'] = get_string($reason, 'mod_adele');
        }

        if (empty($data['participantslist'])) {
            $errors['participantslist'] = get_string('mform_options_required', 'mod_adele');
        }

        // The server decides, not the browser: only an allowed mode, or the
        // stored one unchanged (issue local_adele #600).
        global $USER;
        if (
            isset($data['teachercourseaccessmode'])
            && !teacher_access_mode::may_save(
                $data['teachercourseaccessmode'],
                $this->stored_teachercourseaccessmode(),
                (int) ($data['learningpathid'] ?? 0),
                (int) $USER->id,
                $this->mode_context()
            )
        ) {
            $errors['teachercourseaccessmode'] = teacher_access_mode::is_allowed((string) $data['teachercourseaccessmode'])
                ? get_string('teachercourseaccessmode_noauthority', 'mod_adele')
                : get_string('teachercourseaccessmode_refused', 'mod_adele');
        }

        return $errors;
    }

    /**
     * The context the authority for a teacher mode is checked in: the
     * activity's, or the course's for a new activity.
     *
     * @return \context
     */
    private function mode_context(): \context {
        $cm = $this->get_coursemodule();
        return $cm ? \context_module::instance($cm->id) : \context_course::instance($this->get_course()->id);
    }

    /**
     * The learning path the activity embeds now - 0 for a new one.
     *
     * @return int
     */
    private function stored_learningpathid(): int {
        global $DB;
        if (empty($this->_instance)) {
            return 0;
        }
        return (int) $DB->get_field('adele', 'learningpathid', ['id' => $this->_instance]);
    }

    /**
     * The teacher access mode the activity has stored - null for a new one.
     *
     * @return string|null
     */
    private function stored_teachercourseaccessmode(): ?string {
        global $DB;
        if (empty($this->_instance)) {
            return null;
        }
        $stored = $DB->get_field('adele', 'teachercourseaccessmode', ['id' => $this->_instance]);
        return $stored === false ? null : (string) $stored;
    }

    /**
     * Determines whether completion rules are enabled for this module.
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $mform->addElement(
            'checkbox',
            'completionlearningpathfinished',
            get_string('completionlearningpathfinished', 'mod_adele'),
            get_string('completionlearningpathfinished:desc', 'mod_adele')
        );

        return ['completionlearningpathfinished'];
    }

    /**
     * Determines whether completion rules are enabled for this module.
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionlearningpathfinished']);
    }
}
