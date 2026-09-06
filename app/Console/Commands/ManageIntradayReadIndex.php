<?php

namespace App\Console\Commands;

use App\Support\IntradayReadIndex;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ManageIntradayReadIndex extends Command
{
    protected $signature = 'gex:indexes:intraday
        {--apply : Explicitly apply the additive index after operator preflight}
        {--backup-reference= : Operator-confirmed current backup/restore evidence reference}
        {--database-free-bytes= : Fresh free-space reading from the database host, not this application host}';

    protected $description = 'Inspect the bounded intraday read-index plan; DDL requires explicit apply and operator evidence';

    public function handle(IntradayReadIndex $index): int
    {
        try {
            if ($this->option('apply')) {
                $free = (string) $this->option('database-free-bytes');
                if (! preg_match('/^[0-9]+$/D', $free)
                    || filter_var($free, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                    throw new InvalidArgumentException('--database-free-bytes must be a fresh nonnegative integer reading from the database host.');
                }
                $report = $index->apply((string) $this->option('backup-reference'), (int) $free);
            } else {
                $report = $index->inspect();
                $report['dry_run'] = true;
            }
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $safeMessage = $exception instanceof InvalidArgumentException
                || $exception::class === \RuntimeException::class
                ? $exception->getMessage()
                : 'Database index operation failed; no fallback algorithm or automatic removal was attempted.';
            $this->line(json_encode(['status' => 'refused_or_failed', 'error' => $safeMessage,
                'exception' => $exception::class], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
