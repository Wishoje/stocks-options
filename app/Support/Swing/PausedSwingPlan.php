<?php

namespace App\Support\Swing;

final class PausedSwingPlan
{
    /** A paused, never-qualified plan can yield only after its entry window ends. */
    public static function replaceable(array $state, string $newDate): bool
    {
        $source = $state['source_date'] ?? null;
        $deadline = $state['entry_deadline'] ?? null;
        if (($state['status'] ?? null) !== 'needs_update' || ($state['qualified_at'] ?? null) !== null
            || ! self::validDate($source) || ! self::validDate($deadline) || ! self::validDate($newDate)) {
            return false;
        }

        return $deadline > $source && $newDate > $source && $newDate > $deadline;
    }

    private static function validDate(mixed $date): bool
    {
        return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
