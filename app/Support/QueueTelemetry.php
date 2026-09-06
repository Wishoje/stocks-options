<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

class QueueTelemetry
{
    public const STAMP = 'gex_enqueued_at';

    public const MAX_INSPECTED_PAYLOAD_BYTES = 262144;

    public function __construct(
        private readonly Repository $config,
        private readonly LogManager $logs,
    ) {}

    /** Add metadata only; never alter the serialized job or its arguments. */
    public function payloadMetadata(?int $at = null): array
    {
        return [self::STAMP => $at ?? time()];
    }

    /** Observability must never turn a successfully reserved job into a failure. */
    public function processing(JobProcessing $event, ?int $at = null): void
    {
        try {
            if (! $this->config->get('queue.telemetry.enabled', true)) {
                return;
            }
            $uuid = $event->job->uuid();
            $uuid = is_string($uuid) && preg_match('/^[a-f0-9-]{36}$/iD', $uuid) ? $uuid : null;
            $rate = (float) $this->config->get('queue.telemetry.processing_sample_rate', 0.1);
            $rate = is_finite($rate) ? max(0.0, min(1.0, $rate)) : 0.0;
            if ($rate <= 0 || ($rate < 1 && ($uuid === null
                || hexdec(substr(hash('sha256', $uuid), 0, 8)) / 4294967296 >= $rate))) {
                return;
            }
            $payload = $event->job->payload();
            $age = self::ageFromStamp($payload[self::STAMP] ?? null, $at ?? time());
            $level = (string) $this->config->get('queue.telemetry.log_level', 'info');
            if (! in_array($level, ['debug', 'info'], true)) {
                $level = 'info';
            }
            $this->logs->channel('queue_monitor')->log($level, 'queue.job.processing', [
                'connection' => $this->safeName($event->connectionName),
                'queue' => $this->safeName($event->job->getQueue()),
                'uuid' => $uuid,
                'attempt' => max(1, (int) $event->job->attempts()),
                'age_since_enqueue_seconds' => $age['seconds'],
                'enqueue_age_status' => $age['status'],
                'age_includes_intentional_delay_and_retries' => true,
                'processing_sample_rate' => $rate,
            ]);
        } catch (Throwable) {
            // Do not log the exception recursively: log storage may be down,
            // and exception messages can contain connection credentials.
        }
    }

    /** Inspect only the one ready-list head, never unserialize the job. */
    public static function headReadyAge(mixed $payload, int $at): array
    {
        if ($payload === null || $payload === false || $payload === '') {
            return ['seconds' => null, 'status' => 'queue_empty_or_changed'];
        }
        if (! is_string($payload)) {
            return ['seconds' => null, 'status' => 'invalid_payload'];
        }
        if (strlen($payload) > self::MAX_INSPECTED_PAYLOAD_BYTES) {
            return ['seconds' => null, 'status' => 'payload_exceeds_inspection_limit'];
        }
        try {
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['seconds' => null, 'status' => 'invalid_payload'];
        }
        if (! is_array($decoded)) {
            return ['seconds' => null, 'status' => 'invalid_payload'];
        }

        return self::ageFromStamp($decoded[self::STAMP] ?? null, $at);
    }

    public static function ageFromStamp(mixed $stamp, int $at): array
    {
        if ($stamp === null) {
            return ['seconds' => null, 'status' => 'enqueue_timestamp_not_recorded'];
        }
        if ((! is_int($stamp) && ! (is_string($stamp) && ctype_digit($stamp)))
            || (float) $stamp <= 0 || (float) $stamp > PHP_INT_MAX) {
            return ['seconds' => null, 'status' => 'invalid_enqueue_timestamp'];
        }
        $stamp = (int) $stamp;
        if ($stamp > $at) {
            return ['seconds' => null, 'status' => 'future_enqueue_timestamp'];
        }

        return ['seconds' => $at - $stamp, 'status' => 'recorded'];
    }

    private function safeName(mixed $name): ?string
    {
        return is_string($name) && preg_match('/^[a-zA-Z0-9_{}:.\-]{1,128}$/D', $name) ? $name : null;
    }
}
