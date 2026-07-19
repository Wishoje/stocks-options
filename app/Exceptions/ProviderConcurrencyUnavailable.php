<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderConcurrencyUnavailable extends RuntimeException
{
    // Marker exception used when the shared provider permit cannot be acquired.
}
