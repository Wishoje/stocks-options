<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Safe retry metadata only: never include a request URL, body, or credentials.
 */
final class ProviderDeferred extends RuntimeException
{
    public const CAPACITY = 'provider_capacity';

    public const RATE_WINDOW = 'provider_rate_window';

    public const COOLDOWN = 'provider_cooldown';

    public const COORDINATION = 'provider_coordination_unavailable';

    public const RATE_LIMITED = 'provider_rate_limited';

    public const SERVER_ERROR = 'provider_server_error';

    public const TIMEOUT = 'provider_timeout';

    public const NETWORK = 'provider_network';

    public const BACKPRESSURE = 'queue_backpressure';

    public readonly CarbonImmutable $notBefore;

    public function __construct(
        public readonly string $reason,
        CarbonImmutable $notBefore,
        public readonly ?int $httpStatus = null
    ) {
        if (! in_array($reason, [
            self::CAPACITY, self::RATE_WINDOW, self::COOLDOWN, self::COORDINATION, self::RATE_LIMITED,
            self::SERVER_ERROR, self::TIMEOUT, self::NETWORK, self::BACKPRESSURE,
        ], true)) {
            throw new InvalidArgumentException('Provider deferral reason is not recognized.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Provider deferral HTTP status is invalid.');
        }
        $notBefore = $notBefore->utc();
        if ($notBefore->year < 1 || $notBefore->year > 9999) {
            throw new InvalidArgumentException('Provider retry deadline exceeds the supported timestamp range.');
        }
        $this->notBefore = $notBefore;

        parent::__construct('Provider work deferred: '.$reason.'.', $httpStatus ?? 0);
    }

    public static function reasonForHttpStatus(int $status): ?string
    {
        return match (true) {
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 && $status <= 599 => self::SERVER_ERROR,
            default => null,
        };
    }

    /** Round upwards so second-resolution queue delays do not retry early. */
    public function retryAfterSeconds(CarbonInterface $at): int
    {
        $seconds = $this->notBefore->getTimestamp() - $at->getTimestamp();
        if ($this->notBefore->micro > $at->micro) {
            $seconds++;
        }

        return max(0, $seconds);
    }

    /**
     * This admission made no HTTP attempt; earlier pages in the job may have.
     */
    public function isAdmissionDeferral(): bool
    {
        return in_array($this->reason, [
            self::CAPACITY, self::RATE_WINDOW, self::COOLDOWN, self::COORDINATION, self::BACKPRESSURE,
        ], true);
    }
}
