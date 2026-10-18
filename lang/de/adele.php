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

$string['adele:addinstance'] = 'Lernpfad-Instanz hinzufügen';
$string['adele:addlearningpath'] = 'Kann Lernpfade hinzufügen';
$string['adele:readinstance'] = 'Darf Lernpfad-Instanzen sehen';
$string['adelefieldset'] = 'Lernpfad Einstellungen';
$string['adelename'] = 'Lernpfad';
$string['adelename_help'] = 'Hilfe für Lernpfad';
$string['adelesettings'] = 'Lernpfad Einstellungen';
$string['completiondetail:learningpathfinished'] = 'Lernpfad abschließen';
$string['completionlearningpathfinished'] = 'Lernpfadabschluss';
$string['completionlearningpathfinished:desc'] = 'Aktivität wird abgeschlossen wenn Lernpfad beendet wird';
$string['error_adaptivequiz_mismatch'] = 'Der adaptive Test im Kurs passt nicht zu dem im Lernpfad referenzierten Test.';
$string['error_diverse_scales'] = 'Es wurden unterschiedliche Skalen referenziert. Bitte korrigieren.';
$string['error_multiple_adaptivequiz'] = 'Es wurde mehr als ein adaptiver Test im Kurs gefunden. Bei Nutzung eines Lernpfads ist nur einer erlaubt.';
$string['learningpathdeleted'] = 'Der Lernpfad, den diese Aktivität eingebettet hat, wurde gelöscht.';
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
$string['pluginadministration'] = 'Lernpfad Plugin-Verwaltung';
$string['pluginname'] = 'Lernpfad';
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
$string['teachercourseaccessmode_notallowed'] = '{$a} (auf dieser Website nicht mehr zugelassen)';
$string['teachercourseaccessmode_notallowed_hint'] = 'Die Website-Administration lässt den gespeicherten Modus für den Zugriff von Lehrenden auf verknüpfte Kurse nicht mehr zu. Bis er hier geändert wird, wirkt er wie „Nur vorhandener Zugang“.';
$string['teachercourseaccessmode_refused'] = 'Dieser Modus ist auf dieser Website nicht zugelassen.';
$string['teachercourseaccessmode_request'] = 'Zugang anfragen';
$string['teachercourseaccessmodes'] = 'Zugelassene Modi';
$string['teachercourseaccessmodes_desc'] = 'Die Modi, die eine Aktivität wählen darf. „Nur vorhandener Zugang“ ist immer zugelassen. Ein hier entzogener Modus wirkt in jeder Aktivität, die ihn gespeichert hat, wie „Nur vorhandener Zugang“.';
