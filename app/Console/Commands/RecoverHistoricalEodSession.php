<?php

namespace App\Console\Commands;

use App\Services\HistoricalEodRecoveryService;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RecoverHistoricalEodSession extends Command
{
    protected $signature = 'eod:recover-session
                            {--capture : Capture immutable hybrid recovery artifacts}
                            {--validate : Validate an existing recovery run}
                            {--publish : Publish an already validated recovery run}
                            {--rollback : Roll back the rows published by an exact recovery run}
                            {--date= : Historical session date for capture (YYYY-MM-DD)}
                            {--symbols= : Comma-separated symbol list for capture}
                            {--archive= : Frozen intraday NDJSON or NDJSON.GZ archive path}
                            {--archive-sha= : Expected SHA-256 of the frozen archive}
                            {--run-directory= : New immutable artifact directory for capture}
                            {--run= : Existing recovery run directory for validate/publish/rollback}
                            {--confirm-sha= : Validated candidate SHA-256 for publish/rollback}';

    protected $description = 'Capture, validate, publish, or roll back an isolated historical EOD recovery run.';

    public function handle(HistoricalEodRecoveryService $service): int
    {
        try {
            $mode = $this->mode();
            $result = match ($mode) {
                'capture' => $this->capture($service),
                'publish' => $this->publish($service),
                'rollback' => $this->rollback($service),
                // Validation is the default so an omitted mode can never
                // mutate canonical option-chain data.
                default => $this->validate($service),
            };
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ];
        }

        $encoded = json_encode(
            $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $this->line($encoded === false
            ? '{"ok":false,"error":"Unable to encode recovery result."}'
            : $encoded);

        return (bool) ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function mode(): string
    {
        $selected = array_keys(array_filter([
            'capture' => (bool) $this->option('capture'),
            'validate' => (bool) $this->option('validate'),
            'publish' => (bool) $this->option('publish'),
            'rollback' => (bool) $this->option('rollback'),
        ]));

        if (count($selected) > 1) {
            throw new \InvalidArgumentException(
                'Choose exactly one of --capture, --validate, --publish, or --rollback.'
            );
        }

        return $selected[0] ?? 'validate';
    }

    /** @return array<string,mixed> */
    private function capture(HistoricalEodRecoveryService $service): array
    {
        $date = trim((string) $this->option('date'));
        if (! $this->validDate($date)) {
            throw new \InvalidArgumentException('--date is required for capture and must use YYYY-MM-DD.');
        }

        $symbols = collect(explode(',', (string) $this->option('symbols')))
            ->map(fn (string $symbol): ?string => Symbols::canon($symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($symbols === []) {
            throw new \InvalidArgumentException('--symbols is required for capture.');
        }

        $archive = $this->requiredOption('archive', 'capture');
        $archiveSha = strtolower($this->requiredOption('archive-sha', 'capture'));
        if (! $this->validSha256($archiveSha)) {
            throw new \InvalidArgumentException('--archive-sha must be a 64-character SHA-256 value.');
        }
        $runDirectory = $this->requiredOption('run-directory', 'capture');

        return $service->capture(
            $date,
            $symbols,
            $archive,
            $archiveSha,
            $runDirectory,
        );
    }

    /** @return array<string,mixed> */
    private function validate(HistoricalEodRecoveryService $service): array
    {
        return $service->validate($this->requiredOption('run', 'validation'));
    }

    /** @return array<string,mixed> */
    private function publish(HistoricalEodRecoveryService $service): array
    {
        return $service->publish(
            $this->requiredOption('run', 'publication'),
            $this->confirmationSha('publication'),
        );
    }

    /** @return array<string,mixed> */
    private function rollback(HistoricalEodRecoveryService $service): array
    {
        return $service->rollback(
            $this->requiredOption('run', 'rollback'),
            $this->confirmationSha('rollback'),
        );
    }

    private function confirmationSha(string $mode): string
    {
        $sha = strtolower($this->requiredOption('confirm-sha', $mode));
        if (! $this->validSha256($sha)) {
            throw new \InvalidArgumentException('--confirm-sha must be a 64-character SHA-256 value.');
        }

        return $sha;
    }

    private function requiredOption(string $name, string $mode): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new \InvalidArgumentException("--{$name} is required for {$mode}.");
        }

        return $value;
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
            return false;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date, 'America/New_York');
        } catch (Throwable) {
            return false;
        }

        return $parsed !== null && $parsed->format('Y-m-d') === $date;
    }

    private function validSha256(string $sha): bool
    {
        return (bool) preg_match('/\A[a-f0-9]{64}\z/i', $sha);
    }
}
