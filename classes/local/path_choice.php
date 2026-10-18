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

namespace mod_adele\local;

use local_adele\learning_paths;

/**
 * Which learning path a person may set in an activity (mod_adele #43).
 *
 * A path the person may edit can be chosen. The path an existing activity
 * already embeds can be kept by whoever may edit the activity - without
 * editing rights on the path itself: keeping it grants nothing new. Changing
 * to another path needs editing rights on that path; a path that no longer
 * exists cannot be kept. The server decides from these rules, never from
 * the options the form happened to show.
 *
 * @package    mod_adele
 * @copyright  2026 Wunderbyte GmbH
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_choice {
    /** @var string Allowed. */
    const OK = '';
    /** @var string Nothing chosen. */
    const REQUIRED = 'mform_options_required';
    /** @var string The path does not exist (any more). */
    const MISSING = 'mform_path_missing';
    /** @var string Another path the person may not edit. */
    const NOT_EDITABLE = 'mform_path_not_editable';

    /**
     * The paths the person may choose, by id => name.
     *
     * @return array
     */
    public static function editable(): array {
        $out = [];
        foreach (learning_paths::get_editable_learning_paths() as $record) {
            $out[(int) $record->id] = $record->name;
        }
        return $out;
    }

    /**
     * The options of the form: the editable paths, and the stored one of an
     * existing activity if it still exists.
     *
     * @param int $stored The path the activity embeds now (0 for a new one).
     * @return array id => name
     */
    public static function options(int $stored): array {
        global $DB;
        $options = self::editable();
        if ($stored && !isset($options[$stored])) {
            $name = $DB->get_field('local_adele_learning_paths', 'name', ['id' => $stored]);
            if ($name !== false) {
                $options[$stored] = $name;
            }
        }
        return $options;
    }

    /**
     * Whether the person may set a path; the reason (a string id) if not.
     *
     * @param int $chosen The submitted path.
     * @param int $stored The path the activity embeds now (0 for a new one).
     * @return string self::OK or the reason.
     */
    public static function check(int $chosen, int $stored): string {
        global $DB;
        if ($chosen <= 0) {
            return self::REQUIRED;
        }
        if (!$DB->record_exists('local_adele_learning_paths', ['id' => $chosen])) {
            return self::MISSING;
        }
        if ($chosen === $stored) {
            return self::OK;
        }
        return isset(self::editable()[$chosen]) ? self::OK : self::NOT_EDITABLE;
    }
}
