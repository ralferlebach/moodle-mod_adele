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

/**
 * The live activity markers of one activity (local_adele #14, #17).
 *
 * Per activity: on or off (default off) and the activity window in minutes
 * (default 5, 1-60). The window is what "active" means for the markers and the
 * node counters alike; the polling interval is a site setting of local_adele.
 *
 * @package     mod_adele
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_settings {
    /** @var int Default activity window in minutes. */
    public const DEFAULT_WINDOW = 5;

    /** @var int Shortest activity window in minutes. */
    public const MIN_WINDOW = 1;

    /** @var int Longest activity window in minutes. */
    public const MAX_WINDOW = 60;

    /**
     * Whether a window, in minutes, is allowed.
     *
     * @param mixed $minutes As submitted.
     * @return bool
     */
    public static function is_valid_window($minutes): bool {
        if (!is_numeric($minutes) || (string) (int) $minutes !== trim((string) $minutes)) {
            return false;
        }
        return (int) $minutes >= self::MIN_WINDOW && (int) $minutes <= self::MAX_WINDOW;
    }

    /**
     * A window brought into the allowed range (restore of foreign data).
     *
     * @param int $minutes
     * @return int
     */
    public static function clamp_window(int $minutes): int {
        return max(self::MIN_WINDOW, min(self::MAX_WINDOW, $minutes));
    }

    /**
     * The settings of an activity record.
     *
     * @param \stdClass $instance The adele record (livemarkers, livewindow may be missing on old data).
     * @return array ['enabled' => bool, 'window' => int seconds]
     */
    public static function for_instance(\stdClass $instance): array {
        $minutes = isset($instance->livewindow) ? self::clamp_window((int) $instance->livewindow) : self::DEFAULT_WINDOW;
        return [
            'enabled' => !empty($instance->livemarkers),
            'window' => $minutes * MINSECS,
        ];
    }
}
