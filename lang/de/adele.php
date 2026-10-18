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

$string['accessrequest_badtext'] = 'Die Nachricht darf nicht leer und nicht länger als {$a} Zeichen sein.';
$string['accessrequest_context'] = 'Gesendet von {$a->from} aus dem Lernpfad „{$a->path}“ im Kurs „{$a->host}“. Angefragter Kurs: „{$a->target}“. Seine Teilnehmer/innen: {$a->participants}';
$string['accessrequest_draft'] = 'Ich unterrichte im Kurs „{$a->host}“ und benötige zur Betreuung des Lernpfads „{$a->path}“ Zugriff auf „{$a->target}“. Bitte prüfen Sie, ob mir eine geeignete Kursrolle zugewiesen werden kann.';
$string['accessrequest_hasaccess'] = 'Sie können diesen Kurs bereits öffnen.';
$string['accessrequest_norecipient'] = 'Diese Person kann nicht um Zugang zu diesem Kurs gebeten werden.';
$string['accessrequest_notallowed'] = 'Diese Aktivität bietet keine Anfrage nach Kurszugang an.';
$string['accessrequest_notinpath'] = 'Dieser Kurs gehört nicht zum Lernpfad dieser Aktivität.';
$string['accessrequest_notsent'] = 'Die Anfrage konnte nicht gesendet werden.';
$string['accessrequest_subject'] = 'Anfrage nach Zugang zu „{$a->target}“';
$string['adele:addinstance'] = 'Lernpfad-Instanz hinzufügen';
$string['adele:addlearningpath'] = 'Kann Lernpfade hinzufügen';
$string['adele:delegatecourseaccess'] = 'Einen Einschreibemodus für Lehrende unter der Delegationsrichtlinie der Website wählen';
$string['adele:enrolinnodecourses'] = 'Sich aus der Lehrendenansicht in die Kurse des Lernpfads einschreiben';
$string['adele:readinstance'] = 'Darf Lernpfad-Instanzen sehen';
$string['adelefieldset'] = 'Lernpfad Einstellungen';
$string['adelename'] = 'Lernpfad';
$string['adelename_help'] = 'Hilfe für Lernpfad';
$string['adelesettings'] = 'Lernpfad Einstellungen';
$string['completiondetail:learningpathfinished'] = 'Lernpfad abschließen';
$string['completionlearningpathfinished'] = 'Lernpfadabschluss';
$string['completionlearningpathfinished:desc'] = 'Aktivität wird abgeschlossen wenn Lernpfad beendet wird';
$string['enrol_failed'] = 'Die Einschreibung konnte nicht abgeschlossen werden.';
$string['enrol_hidden'] = 'Der Kurs ist verborgen, und die Rolle der Einschreibung könnte ihn nicht öffnen.';
$string['enrol_noauthority'] = 'Die Person, die diesen Modus für die Aktivität gewählt hat, hat die Berechtigung dafür nicht mehr.';
$string['enrol_nopermission'] = 'Sie dürfen sich nicht in die Kurse dieses Lernpfads einschreiben.';
$string['enrol_norole'] = 'Die Website hat keine Rolle für diese Einschreibung.';
$string['enrol_notallowed'] = 'Diese Aktivität bietet keine Einschreibung in ihre Kurse an.';
$string['enrol_source'] = 'Aktivität „{$a->activity}“ im Kurs „{$a->course}“';
$string['enrol_unavailable'] = 'Die Einschreibung in die Kurse eines Lernpfads ist auf dieser Website nicht verfügbar.';
$string['error_adaptivequiz_mismatch'] = 'Der adaptive Test im Kurs passt nicht zu dem im Lernpfad referenzierten Test.';
$string['error_diverse_scales'] = 'Es wurden unterschiedliche Skalen referenziert. Bitte korrigieren.';
$string['error_multiple_adaptivequiz'] = 'Es wurde mehr als ein adaptiver Test im Kurs gefunden. Bei Nutzung eines Lernpfads ist nur einer erlaubt.';
$string['event_course_access_requested'] = 'Kurszugang angefragt';
$string['event_node_course_enrolled'] = 'Lehrperson in einen Kurs des Lernpfads eingeschrieben';
$string['learningpathdeleted'] = 'Der Lernpfad, den diese Aktivität eingebettet hat, wurde gelöscht.';
$string['learningpathnotset'] = 'Für diese Aktivität ist noch kein Lernpfad gewählt. Lehrende können ihn in den Einstellungen der Aktivität wählen.';
$string['messageprovider:courseaccessrequest'] = 'Anfragen von Lehrenden nach Zugang zu einem Kurs eines Lernpfads';
$string['mform_options_create_learningpath'] = 'Neuen Lernpfad erstellen';
$string['mform_options_hostenrolmentmode_hidden'] = 'verdeckt (als deaktivierte Einschreibung)';
$string['mform_options_hostenrolmentmode_none'] = 'keine Einschreibung vornehmen';
$string['mform_options_hostenrolmentmode_visible'] = 'automatisch (mit vollem Zugriff für Teilnehmende)';
$string['mform_options_link_create_learningpath'] = 'Link zur Erstellung eines Lernpfades';
$string['mform_options_no_selection'] = 'Keine Auswahl';
$string['mform_options_participantslist_all_courses'] = 'für in einem beliebigen Kurs des Lernpfades eingeschriebene Personen';
$string['mform_options_participantslist_starting_courses'] = 'für in Startknoten eingeschriebene Personen';
$string['mform_options_participantslist_this_course'] = 'für in diesem Kurs eingeschriebene Personen';
$string['mform_options_required'] = 'notwendig';
$string['mform_options_userlist_all'] = 'Ergebnisse aller anderer Teilnehmer';
$string['mform_options_userlist_only'] = 'nur eigene Ergebnisse';
$string['mform_options_view_floor_level'] = 'innerhalb der Aktivität';
$string['mform_options_view_top_level'] = 'direkt auf Kursebene';
$string['mform_select_hostenrolmentmode'] = 'Einschreibung in diesem Kurs';
$string['mform_select_hostenrolmentmode_help'] = 'Nur relevant für die Teilnehmeroptionen „für in Startknoten eingeschriebene Personen" und „für in einem beliebigen Kurs des Lernpfades eingeschriebene Personen" — die Option „für in diesem Kurs eingeschriebene Personen" schreibt immer aktiv in diesen Kurs ein. Automatisch: Die Person wird aktiv eingeschrieben und kann den Kurs betreten. Verdeckt: Eine Einschreibung wird angelegt (in der Teilnehmerliste sichtbar, für Berichte/Zertifikate zählbar), gewährt aber keinen Zugriff. Keine Einschreibung: Hier entsteht keine Einschreibung — nur die Lernpfad-Zuordnung selbst erfolgt.';
$string['mform_select_learningpath'] = 'Gewählter Lernpfad';
$string['mform_select_participantslist'] = 'Lernpfadeinschreibung';
$string['mform_select_userlist'] = 'Teilnehmer sehen';
$string['mform_select_view'] = 'Lernpfad anzeigen';
$string['mod/adele:seelearningpath'] = 'Kann Lernpfade sehen';
$string['modulename'] = 'Lernpfad';
$string['modulenameplural'] = 'Lernpfade';
$string['noadeleinstances'] = 'In diesem Kurs gibt es keine Lernpfad-Aktivitäten.';
$string['pluginadministration'] = 'Lernpfad Plugin-Verwaltung';
$string['pluginname'] = 'Lernpfad';
$string['privacy:metadata:adele'] = 'Wer den Lehrenden-Zugangsmodus einer Lernpfad-Aktivität gewählt hat: Der Modus beruht auf der Befugnis dieser Person.';
$string['privacy:metadata:adele:teachercourseaccessgrantor'] = 'Die ID der Person, die den Lehrenden-Zugangsmodus gewählt hat.';
$string['privacy:metadata:adele:teachercourseaccessmode'] = 'Der Lehrenden-Zugangsmodus, den diese Person gewählt hat.';
$string['restore_pathunset'] = 'Der Lernpfad {$a} der Sicherung existiert auf dieser Website nicht (oder die Sicherung stammt von einer anderen Website): Die Aktivität wurde ohne Lernpfad wiederhergestellt.';
$string['sourcesincludesuspended'] = 'Ausgesetzte Einschreibungen zählen als Teilnehmerquelle';
$string['sourcesincludesuspended_desc'] = 'Aus (empfohlen): Nur eine wirksame eigene Einschreibung – aktiv, im Zeitraum, über eine aktivierte Methode, nicht von ADELE angelegt – abonniert einen Lernpfad und trägt den Zugang zu einem Gastkurs. An: Auch ausgesetzte Einschreibungen zählen (das Verhalten vor dieser Einstellung). Eine Änderung wendet die Quellen aller Aktivitäten neu an.';
$string['task_reconcile_teacher_grants'] = 'Einschreibungen von Lehrenden in Lernpfad-Kurse zurücknehmen, deren Grundlage entfallen ist';
$string['teachercourseaccess_heading'] = 'Zugriff von Lehrenden auf die Kurse der Knoten';
$string['teachercourseaccess_heading_desc'] = 'In der Lehrendenansicht einer Lernpfad-Aktivität sehen Lehrende die Kurse des ausgewählten Knotens. Ein Kurs, den sie schon öffnen können, erhält immer den Link „Kurs öffnen“. Diese Einstellungen legen fest, was eine Aktivität für einen Kurs anbieten darf, den sie noch nicht öffnen können.';
$string['teachercourseaccessdefault'] = 'Standard für neue Aktivitäten';
$string['teachercourseaccessdefault_desc'] = 'Der Modus, mit dem eine neue Lernpfad-Aktivität beginnt. Ist er nicht unter den zugelassenen Modi, beginnen neue Aktivitäten mit „Nur vorhandener Zugang“. Eine Änderung ändert bestehende Aktivitäten nicht.';
$string['teachercourseaccessmode'] = 'Zugriff von Lehrenden auf verknüpfte Kurse';
$string['teachercourseaccessmode_enrol_editingteacher'] = 'Als Trainer/in (mit Bearbeitungsrecht) einschreiben';
$string['teachercourseaccessmode_enrol_student'] = 'Als Teilnehmer/in einschreiben';
$string['teachercourseaccessmode_enrol_teacher'] = 'Als Trainer/in ohne Bearbeitungsrecht einschreiben';
$string['teachercourseaccessmode_existing_only'] = 'Nur vorhandener Zugang';
$string['teachercourseaccessmode_help'] = 'Was Lehrenden dieser Aktivität für einen Kurs eines Knotens angeboten wird, den sie noch nicht öffnen können. Ein Kurs, den sie schon öffnen können, erhält immer den Link „Kurs öffnen“. Welche Modi gewählt werden dürfen, legt die Website-Administration fest.';
$string['teachercourseaccessmode_locked'] = '{$a} (gesperrt)';
$string['teachercourseaccessmode_locked_hint'] = 'Nur wer diese Rolle in allen Kursen des Lernpfads zuweisen darf, kann diesen Modus ändern.';
$string['teachercourseaccessmode_noauthority'] = 'Ihnen fehlt für die Kurse des gewählten Lernpfads die Berechtigung für diesen Modus.';
$string['teachercourseaccessmode_notallowed'] = '{$a} (auf dieser Website nicht mehr zugelassen)';
$string['teachercourseaccessmode_notallowed_hint'] = 'Die Website-Administration lässt den gespeicherten Modus für den Zugriff von Lehrenden auf verknüpfte Kurse nicht mehr zu. Bis er hier geändert wird, wirkt er wie „Nur vorhandener Zugang“.';
$string['teachercourseaccessmode_refused'] = 'Dieser Modus ist auf dieser Website nicht zugelassen.';
$string['teachercourseaccessmode_request'] = 'Zugang anfragen';
$string['teachercourseaccessmodes'] = 'Zugelassene Modi';
$string['teachercourseaccessmodes_desc'] = 'Die Modi, die eine Aktivität wählen darf. „Nur vorhandener Zugang“ ist immer zugelassen. Ein hier entzogener Modus wirkt in jeder Aktivität, die ihn gespeichert hat, wie „Nur vorhandener Zugang“.';
$string['teachercourseaccesspolicy'] = 'Richtlinie für die Einschreibemodi für Lehrende';
$string['teachercourseaccesspolicy_1'] = '1 – Angezeigt, aber gesperrt';
$string['teachercourseaccesspolicy_2'] = '2 – Gespeicherten Modus behalten oder herabsetzen';
$string['teachercourseaccesspolicy_3'] = '3 – Delegation durch die Website';
$string['teachercourseaccesspolicy_desc'] = 'Wer für eine Aktivität „Als Trainer/in ohne Bearbeitungsrecht einschreiben“ oder „Als Trainer/in (mit Bearbeitungsrecht) einschreiben“ wählen darf. Immer: wer die Rolle des Modus in jedem Kurs des Lernpfads zuweisen darf. Richtlinie 1: alle anderen sehen die Option gesperrt und können einen gespeicherten Modus nicht ändern. Richtlinie 2: alle anderen dürfen einen gespeicherten Modus behalten oder herabsetzen, nie heraufsetzen. Richtlinie 3: zusätzlich, wer mod/adele:delegatecourseaccess in der Aktivität hat. Ein Modus erteilt Einschreibungen nur, solange die Person, die ihn gewählt hat, diese Berechtigung noch hat.';
$string['teachercourseaccessrequestfallback'] = 'Ansprechpartner, wenn niemand gefragt werden kann';
$string['teachercourseaccessrequestfallback_desc'] = 'Wird bei „Zugang anfragen“ angezeigt, wenn ein Kurs niemanden hat, der gefragt werden kann – etwa die Adresse Ihres Supports. Die Lehrperson kann die vorausgefüllte Nachricht kopieren und dorthin senden.';
$string['teachercourseaccessrequestrecipients'] = 'Wer gefragt wird';
$string['teachercourseaccessrequestrecipients_desc'] = 'Eine Anfrage geht an ein aktives Mitglied des angefragten Kurses – nie an die Lehrenden des Kurses, der den Lernpfad einbettet. Hier wird gewählt, an welche Mitglieder.';
$string['teachercourseaccessrequestrecipients_enrolers'] = 'Mitglieder, die einschreiben dürfen (enrol/manual:enrol)';
$string['teachercourseaccessrequestrecipients_enrolreview'] = 'Mitglieder, die Einschreibungen prüfen dürfen (moodle/course:enrolreview)';
$string['teachercourseaccessrole'] = 'Rolle für „{$a}“';
$string['teachercourseaccessrole_desc'] = 'Die Rolle, die eine Einschreibung in diesem Modus im Kurs des Lernpfads erteilt. Eine Änderung nimmt die mit der früheren Rolle erteilten Einschreibungen zurück.';
