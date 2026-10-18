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
 * Plugin strings are defined here.
 *
 * @package     mod_adele
 * @category    string
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @copyright   2026 Ralf Erlebach
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['accessrequest_badtext'] = 'The message must not be empty and not longer than {$a} characters.';
$string['accessrequest_context'] = 'Sent by {$a->from} from the learning path "{$a->path}" in the course "{$a->host}". Course asked for: "{$a->target}". Its participants: {$a->participants}';
$string['accessrequest_draft'] = 'I teach in the course "{$a->host}" and, to look after the learning path "{$a->path}", I need access to "{$a->target}". Could you please check whether I can be given a suitable role in that course?';
$string['accessrequest_hasaccess'] = 'You can already open this course.';
$string['accessrequest_norecipient'] = 'This person cannot be asked for access to this course.';
$string['accessrequest_notallowed'] = 'This activity does not offer requests for course access.';
$string['accessrequest_notinpath'] = 'This course is not part of the learning path of this activity.';
$string['accessrequest_notsent'] = 'The request could not be sent.';
$string['accessrequest_subject'] = 'Request for access to "{$a->target}"';
$string['adele:addinstance'] = 'Add Learning path Instance';
$string['adele:addlearningpath'] = 'Can add learning paths';
$string['adele:delegatecourseaccess'] = 'Choose a teacher enrolment mode under the site\'s delegation policy';
$string['adele:enrolinnodecourses'] = 'Enrol oneself into the courses of the learning path from the teacher view';
$string['adele:readinstance'] = 'Is allowed to view instances of the learning path plugin';
$string['adelefieldset'] = 'Learning path Settings';
$string['adelename'] = 'Learning path';
$string['adelename_help'] = 'Help for Learning path';
$string['adelesettings'] = 'Learning path Settings';
$string['completiondetail:learningpathfinished'] = 'Finish the learning path';
$string['completionlearningpathfinished'] = 'Learningpath completion';
$string['completionlearningpathfinished:desc'] = 'Activity completion will be triggered if learning path is finished';
$string['enrol_failed'] = 'The enrolment could not be completed.';
$string['enrol_hidden'] = 'The course is hidden, and the role of the enrolment could not open it.';
$string['enrol_noauthority'] = 'The person who chose this mode for the activity no longer has the authority for it.';
$string['enrol_nopermission'] = 'You may not enrol into the courses of this learning path.';
$string['enrol_norole'] = 'The site has no role for this enrolment.';
$string['enrol_notallowed'] = 'This activity does not offer enrolment into its courses.';
$string['enrol_source'] = 'Activity "{$a->activity}" in the course "{$a->course}"';
$string['enrol_unavailable'] = 'Enrolment into the courses of a learning path is not available on this site.';
$string['error_adaptivequiz_mismatch'] = 'The adaptive quiz in the course does not match the one referenced in the learning path.';
$string['error_diverse_scales'] = 'Different scales were referenced. Please fix this.';
$string['error_multiple_adaptivequiz'] = 'More than one adaptive quiz was found in the course. Only one is allowed when a learning path is used.';
$string['event_course_access_requested'] = 'Course access requested';
$string['event_node_course_enrolled'] = 'Teacher enrolled into a course of the learning path';
$string['learningpathdeleted'] = 'The learning path this activity embedded has been deleted.';
$string['messageprovider:courseaccessrequest'] = 'Requests from teachers for access to a course of a learning path';
$string['mform_options_create_learningpath'] = 'Create new learning path';
$string['mform_options_hostenrolmentmode_hidden'] = 'hidden (as a suspended enrolment)';
$string['mform_options_hostenrolmentmode_none'] = 'no enrolment at all';
$string['mform_options_hostenrolmentmode_visible'] = 'automatic (with full access for participants)';
$string['mform_options_link_create_learningpath'] = 'Link to learning path creation';
$string['mform_options_no_selection'] = 'No selection';
$string['mform_options_participantslist_all_courses'] = 'for people enrolled in any course of the learning path';
$string['mform_options_participantslist_starting_courses'] = 'for people enrolled in a starting node';
$string['mform_options_participantslist_this_course'] = 'for people enrolled in this course';
$string['mform_options_required'] = 'required';
$string['mform_options_userlist_all'] = 'Results of all other participants';
$string['mform_options_userlist_only'] = 'Only own results';
$string['mform_options_view_floor_level'] = 'inside the activity';
$string['mform_options_view_top_level'] = 'directly on the course page';
$string['mform_select_hostenrolmentmode'] = 'Enrolment in this course';
$string['mform_select_hostenrolmentmode_help'] = 'Only relevant for the participant options "for people enrolled in a starting node" and "for people enrolled in any course of the learning path" — the option "for people enrolled in this course" always enrols actively into this course. Automatic: the person is actively enrolled and can access this course. Hidden: an enrolment record is kept (visible in the participant list and countable for reports/certificates) but the person gets no access. No enrolment: no enrolment is created here — only the learning path assignment itself happens.';
$string['mform_select_learningpath'] = 'Chosen Learning Path';
$string['mform_select_participantslist'] = 'Learning path enrolment';
$string['mform_select_userlist'] = 'Participant results';
$string['mform_select_view'] = 'Display learning path';
$string['mod/adele:seelearningpath'] = 'Can see learning paths';
$string['modulename'] = 'Learning path';
$string['modulenameplural'] = 'Learning paths';
$string['pluginadministration'] = 'Learning path Plugin Administration';
$string['pluginname'] = 'Learning path';
$string['task_reconcile_teacher_grants'] = 'Take away teachers\' enrolments into learning path courses that no longer hold';
$string['teachercourseaccess_heading'] = 'Teachers\' access to the node courses';
$string['teachercourseaccess_heading_desc'] = 'In the teacher view of a learning path activity, teachers see the courses of the node they select. A course they can already open always gets an "Open course" link. These settings decide what an activity may offer for a course they cannot open yet.';
$string['teachercourseaccessdefault'] = 'Default for new activities';
$string['teachercourseaccessdefault_desc'] = 'The mode a new learning path activity starts with. If it is not among the allowed modes, new activities start with "Existing access only". Changing it does not change existing activities.';
$string['teachercourseaccessmode'] = 'Teachers\' access to linked courses';
$string['teachercourseaccessmode_enrol_editingteacher'] = 'Enrol as teacher (with editing rights)';
$string['teachercourseaccessmode_enrol_student'] = 'Enrol as participant';
$string['teachercourseaccessmode_enrol_teacher'] = 'Enrol as non-editing teacher';
$string['teachercourseaccessmode_existing_only'] = 'Existing access only';
$string['teachercourseaccessmode_help'] = 'What teachers of this activity are offered for a node course they cannot open yet. A course they can already open always gets an "Open course" link. The site administration decides which modes may be chosen.';
$string['teachercourseaccessmode_locked'] = '{$a} (locked)';
$string['teachercourseaccessmode_locked_hint'] = 'Only somebody who may assign this role in all courses of the learning path can change this mode.';
$string['teachercourseaccessmode_noauthority'] = 'You do not have the authority for this mode for the courses of the chosen learning path.';
$string['teachercourseaccessmode_notallowed'] = '{$a} (no longer allowed on this site)';
$string['teachercourseaccessmode_notallowed_hint'] = 'The site administration no longer allows the stored mode for teachers\' access to linked courses. Until it is changed here, it acts as "Existing access only".';
$string['teachercourseaccessmode_refused'] = 'This mode is not allowed on this site.';
$string['teachercourseaccessmode_request'] = 'Request access';
$string['teachercourseaccessmodes'] = 'Allowed modes';
$string['teachercourseaccessmodes_desc'] = 'The modes an activity may choose. "Existing access only" is always allowed. A mode taken away here acts as "Existing access only" in every activity that has stored it.';
$string['teachercourseaccesspolicy'] = 'Policy for the teacher enrolment modes';
$string['teachercourseaccesspolicy_1'] = '1 - Shown, but locked';
$string['teachercourseaccesspolicy_2'] = '2 - Keep or lower a stored mode';
$string['teachercourseaccesspolicy_3'] = '3 - Delegation by the site';
$string['teachercourseaccesspolicy_desc'] = 'Who may choose "Enrol as non-editing teacher" or "Enrol as teacher (with editing rights)" for an activity. Always: who may assign the mode\'s role in every course of the learning path. Policy 1: anybody else sees the option locked and cannot change a stored one. Policy 2: anybody else may keep a stored one or lower it, never raise it. Policy 3: also who holds mod/adele:delegatecourseaccess in the activity. A mode gives enrolments only while the person who chose it still has that authority.';
$string['teachercourseaccessrequestfallback'] = 'Contact when nobody can be asked';
$string['teachercourseaccessrequestfallback_desc'] = 'Shown with "Request access" when a course has nobody who may be asked - for example the address of your support. The teacher can copy the prefilled message and send it there.';
$string['teachercourseaccessrequestrecipients'] = 'Who is asked';
$string['teachercourseaccessrequestrecipients_desc'] = 'A request goes to one active member of the course asked for - never to the teachers of the course that embeds the learning path. Chosen here: which members.';
$string['teachercourseaccessrequestrecipients_enrolers'] = 'Members who may enrol people (enrol/manual:enrol)';
$string['teachercourseaccessrequestrecipients_enrolreview'] = 'Members who may review enrolments (moodle/course:enrolreview)';
$string['teachercourseaccessrole'] = 'Role for "{$a}"';
$string['teachercourseaccessrole_desc'] = 'The role an enrolment in this mode gives in the course of the learning path. Changing it takes away the enrolments given with the earlier role.';
