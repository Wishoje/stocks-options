<?php

namespace App\Exceptions;

use RuntimeException;

class WorkRunRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Work-run admission is temporarily rate limited.');
    }
}
