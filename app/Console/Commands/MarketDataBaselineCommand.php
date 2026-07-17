<?php

namespace App\Console\Commands;

use App\Support\Regression\BaselineComparator;
use App\Support\Regression\CanonicalJson;
use App\Support\Regression\MarketDataBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MarketDataBaselineCommand extends Command
{
    protected $signature = 'market-data:baseline
                            {action : capture or compare}
                            {--symbols=SPY,QQQ,IWM,AAPL,MSFT : Comma-separated symbol scope}
                            {--date= : Trade date in YYYY-MM-DD}
                            {--output=storage/app/regression/market-data-baseline.json : Capture output path}
                            {--baseline= : Baseline JSON path for compare}
                            {--candidate= : Candidate JSON path; omit to capture the current database}
                            {--api-dir= : Optional directory of named JSON API responses to include}';

    protected $description = 'Capture or compare a canonical, credential-free market-data regression artifact';

    public function handle(MarketDataBaseline $baselineService, BaselineComparator $comparator): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        return match ($action) {
            'capture' => $this->capture($baselineService),
            'compare' => $this->compare($baselineService, $comparator),
            default => $this->invalidAction($action),
        };
    }

    private function capture(MarketDataBaseline $service): int
    {
        try {
            $artifact = $service->capture(
                $this->symbols(),
                $this->dateOption(),
                $this->loadApiDirectory()
            );
            $path = $this->resolvePath((string) $this->option('output'));
            File::ensureDirectoryExists(dirname($path));
            File::put($path, CanonicalJson::encode($artifact, true).PHP_EOL);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Captured schema v%d baseline for %d symbols at %s',
            MarketDataBaseline::SCHEMA_VERSION,
            count($artifact['scope']['symbols'] ?? []),
            $path
        ));

        return self::SUCCESS;
    }

    private function compare(MarketDataBaseline $service, BaselineComparator $comparator): int
    {
        $baselineOption = trim((string) $this->option('baseline'));
        if ($baselineOption === '') {
            $this->error('--baseline is required for compare.');

            return self::FAILURE;
        }

        try {
            $baseline = $this->loadArtifact($baselineOption);
            $candidateOption = trim((string) $this->option('candidate'));

            if ($candidateOption !== '') {
                $candidate = $this->loadArtifact($candidateOption);
            } else {
                $symbols = $this->symbolsFromBaselineOrOption($baseline);
                $date = $this->dateOption()
                    ?: (string) data_get($baseline, 'scope.trade_date', '');
                $candidate = $service->capture($symbols, $date ?: null, $this->loadApiDirectory());
            }

            $result = $comparator->compare($baseline, $candidate);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['matches']) {
            $this->info('Candidate matches the baseline.');

            return self::SUCCESS;
        }

        $this->error(sprintf('Candidate differs at %d path(s).', count($result['differences'])));
        foreach (array_slice($result['differences'], 0, 50) as $difference) {
            $this->line(sprintf(
                '- [%s] %s (baseline=%s, candidate=%s)',
                $difference['type'],
                $difference['path'],
                $this->displayValue($difference['baseline']),
                $this->displayValue($difference['candidate']),
            ));
        }

        if (count($result['differences']) > 50) {
            $this->line('- Additional differences omitted from console output.');
        }

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action [{$action}]. Use capture or compare.");

        return self::FAILURE;
    }

    /** @return array<int,string> */
    private function symbols(): array
    {
        return collect(explode(',', (string) $this->option('symbols')))
            ->map(fn (string $symbol): string => trim($symbol))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @return array<int,string>
     */
    private function symbolsFromBaselineOrOption(array $baseline): array
    {
        $optionValue = trim((string) $this->option('symbols'));
        if ($optionValue !== '' && $optionValue !== 'SPY,QQQ,IWM,AAPL,MSFT') {
            return $this->symbols();
        }

        $symbols = data_get($baseline, 'scope.symbols', []);

        return is_array($symbols) && $symbols !== [] ? array_values($symbols) : $this->symbols();
    }

    private function dateOption(): ?string
    {
        $date = trim((string) $this->option('date'));

        return $date === '' ? null : $date;
    }

    /** @return array<string,mixed> */
    private function loadArtifact(string $path): array
    {
        $resolved = $this->resolvePath($path);
        if (!File::isFile($resolved)) {
            throw new \RuntimeException("Artifact not found: {$resolved}");
        }

        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Artifact must contain a JSON object: {$resolved}");
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function loadApiDirectory(): array
    {
        $directory = trim((string) $this->option('api-dir'));
        if ($directory === '') {
            return [];
        }

        $resolved = $this->resolvePath($directory);
        if (!File::isDirectory($resolved)) {
            throw new \RuntimeException("API payload directory not found: {$resolved}");
        }

        $payloads = [];
        foreach (File::files($resolved) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $payloads[$file->getFilenameWithoutExtension()] = json_decode(
                File::get($file->getPathname()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        ksort($payloads, SORT_STRING);

        return $payloads;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('A non-empty path is required.');
        }

        $isAbsolute = str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : base_path($path);
    }

    private function displayValue(mixed $value): string
    {
        $encoded = is_string($value)
            ? $value
            : CanonicalJson::encode($value);

        return sprintf(
            '<%s length=%d sha256=%s>',
            get_debug_type($value),
            strlen($encoded),
            substr(hash('sha256', $encoded), 0, 12)
        );
    }
}
