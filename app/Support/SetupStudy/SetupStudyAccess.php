<?php

namespace App\Support\SetupStudy;

final class SetupStudyAccess
{
    public static function allows(mixed $user): bool
    {
        return $user !== null && strtolower(trim((string) $user->email)) === strtolower(trim((string) config('setup_study.owner_email')));
    }
}
