<?php

namespace Tests\Feature;

use App\Support\OptionLiveTotalsRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySqlTestCase;
use Throwable;

class OptionLiveTotalsConcurrencyTest extends MySqlTestCase
{
    /** @var list<string> */
    private array $symbols = ['G12OLDER', 'G12NEWER'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('option_live_totals')->whereIn('symbol', $this->symbols)->delete();
    }

    protected function tearDown(): void
    {
        DB::table('option_live_totals')->whereIn('symbol', $this->symbols)->delete();

        parent::tearDown();
    }

    public function test_newer_write_waiting_on_an_older_write_wins_atomically(): void
    {
        $this->requireForkSupport();

        $this->runOverlappingWrites(
            'G12OLDER',
            $this->payload('G12OLDER', 10, '2026-08-14 19:50:00', 101),
            $this->payload('G12OLDER', 25, '2026-08-14 19:55:00', 102),
        );

        $this->assertStoredWinner('G12OLDER', 25, '2026-08-14 19:55:00.000000', 102);
    }

    public function test_older_write_waiting_on_a_newer_write_cannot_regress_it(): void
    {
        $this->requireForkSupport();

        $this->runOverlappingWrites(
            'G12NEWER',
            $this->payload('G12NEWER', 40, '2026-08-14 19:58:00', 202),
            $this->payload('G12NEWER', 5, '2026-08-14 19:45:00', 201),
        );

        $this->assertStoredWinner('G12NEWER', 40, '2026-08-14 19:58:00.000000', 202);
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function runOverlappingWrites(string $symbol, array $first, array $second): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gex012-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create concurrency barrier directory [{$directory}].");
        }

        $ready = $directory.DIRECTORY_SEPARATOR.'first-ready';
        $attempted = $directory.DIRECTORY_SEPARATOR.'second-attempted';
        $firstError = $directory.DIRECTORY_SEPARATOR.'first-error';
        $secondError = $directory.DIRECTORY_SEPARATOR.'second-error';
        $connection = DB::getDefaultConnection();

        DB::disconnect($connection);

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            throw new RuntimeException('Unable to fork the first MySQL writer.');
        }

        if ($firstPid === 0) {
            $this->childWrite(
                $connection,
                $first,
                beforeWrite: null,
                afterWrite: function () use ($ready, $attempted): void {
                    touch($ready);
                    $this->waitForFile($attempted);
                    usleep(250_000);
                },
                errorPath: $firstError,
            );
        }

        $secondPid = pcntl_fork();
        if ($secondPid === -1) {
            touch($attempted);
            pcntl_waitpid($firstPid, $firstStatus);
            throw new RuntimeException('Unable to fork the second MySQL writer.');
        }

        if ($secondPid === 0) {
            $this->childWrite(
                $connection,
                $second,
                beforeWrite: function () use ($ready, $attempted): void {
                    $this->waitForFile($ready);
                    touch($attempted);
                },
                afterWrite: null,
                errorPath: $secondError,
            );
        }

        pcntl_waitpid($firstPid, $firstStatus);
        pcntl_waitpid($secondPid, $secondStatus);
        DB::purge($connection);
        DB::reconnect($connection);

        try {
            $this->assertTrue(
                pcntl_wifexited($firstStatus) && pcntl_wexitstatus($firstStatus) === 0,
                is_file($firstError) ? (string) file_get_contents($firstError) : 'First writer failed.'
            );
            $this->assertTrue(
                pcntl_wifexited($secondStatus) && pcntl_wexitstatus($secondStatus) === 0,
                is_file($secondError) ? (string) file_get_contents($secondError) : 'Second writer failed.'
            );
            $this->assertSame(
                1,
                DB::table('option_live_totals')
                    ->where('symbol', $symbol)
                    ->whereDate('trade_date', '2026-08-14')
                    ->count()
            );
        } finally {
            foreach ([$ready, $attempted, $firstError, $secondError] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function childWrite(
        string $connection,
        array $payload,
        ?callable $beforeWrite,
        ?callable $afterWrite,
        string $errorPath,
    ): never {
        try {
            DB::purge($connection);
            DB::reconnect($connection);
            if ($beforeWrite !== null) {
                $beforeWrite();
            }

            DB::beginTransaction();
            app(OptionLiveTotalsRepository::class)->store($payload);
            if ($afterWrite !== null) {
                $afterWrite();
            }
            DB::commit();

            exit(0);
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            file_put_contents($errorPath, $exception::class.': '.$exception->getMessage());
            exit(1);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for concurrency barrier [{$path}].");
            }
            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $symbol, int $volume, string $asof, int $sourceRowId): array
    {
        return [
            'symbol' => $symbol,
            'trade_date' => '2026-08-14',
            'call_volume' => $volume,
            'put_volume' => $volume + 1,
            'volume' => ($volume * 2) + 1,
            'premium_usd' => $volume * 100,
            'asof' => $asof,
            'source_updated_at' => $asof,
            'source_row_id' => $sourceRowId,
        ];
    }

    private function assertStoredWinner(
        string $symbol,
        int $callVolume,
        string $asof,
        int $sourceRowId,
    ): void {
        $row = DB::table('option_live_totals')
            ->where('symbol', $symbol)
            ->whereDate('trade_date', '2026-08-14')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($callVolume, (int) $row->call_volume);
        $this->assertSame($callVolume + 1, (int) $row->put_volume);
        $this->assertSame(($callVolume * 2) + 1, (int) $row->volume);
        $this->assertSame($sourceRowId, (int) $row->source_row_id);
        $this->assertSame($asof, (string) $row->asof);
    }

    private function requireForkSupport(): void
    {
        if (function_exists('pcntl_fork')) {
            return;
        }

        if (filter_var(getenv('CI'), FILTER_VALIDATE_BOOL)) {
            $this->fail('CI must provide pcntl so the true MySQL concurrency proof cannot be skipped.');
        }

        $this->markTestSkipped('The true MySQL concurrency proof requires the pcntl extension.');
    }
}
