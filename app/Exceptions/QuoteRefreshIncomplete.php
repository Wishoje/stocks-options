<?php

namespace App\Exceptions;

use RuntimeException;

final class QuoteRefreshIncomplete extends RuntimeException
{
    // The provider returned no usable quote for one or more requested symbols.
}
