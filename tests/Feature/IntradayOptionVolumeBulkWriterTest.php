<?php

namespace Tests\Feature;

use App\Services\IntradayOptionVolumeIngestor;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\MySqlTestCase;

class IntradayOptionVolumeBulkWriterTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-18 17:00:00', 'UTC'));
        config()->set('intraday_ingestion.bulk_enabled', true);
        config()->set('intraday_ingestion.chunk_size', 250);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bulk_rows_match_the_legacy_writer_for_every_stored_field(): void
    {
        $capturedAt = Carbon::parse('2026-03-18 16:59:03', 'UTC');
        $contracts = [$this->contract(1), $this->contract(2), $this->contract(3)];
        $contracts[0]['details']['strike_price'] = '512.12345';
        $contracts[0]['day'] = [
            'volume' => '4294967296',
            'close' => '12345.1234567',
            'change' => '-0.0000006',
            'change_percent' => '999.1234567',
        ];
        $contracts[0]['open_interest'] = '4294967297';
        $contracts[0]['implied_volatility'] = '0.1234567';
        $contracts[0]['greeks'] = [
            'delta' => '0.12345678915',
            'gamma' => '0.00000000006',
            'theta' => '-0.12345678915',
            'vega' => '123.12345678915',
        ];
        $contracts[1]['day'] = ['volume' => 0, 'close' => 0, 'change' => 0, 'change_percent' => 0];
        $contracts[1]['open_interest'] = 0;
        $contracts[1]['implied_volatility'] = 0;
        $contracts[1]['greeks'] = ['delta' => 0, 'gamma' => 0, 'theta' => 0, 'vega' => 0];
        unset($contracts[2]['day'], $contracts[2]['greeks'], $contracts[2]['open_interest'], $contracts[2]['implied_volatility']);

        $ingestor = app(IntradayOptionVolumeIngestor::class);
        foreach ($contracts as $contract) {
            $ingestor->ingest($contract, 'all-fields-fixture', $capturedAt);
        }
        $expected = $this->storedRows();
        DB::table('intraday_option_volumes')->where('symbol', 'BULK')->delete();

        $this->assertSame(3, $ingestor->ingestMany($contracts, 'all-fields-fixture', $capturedAt));
        $actual = $this->storedRows();

        $this->assertSame($expected, $actual);
        $this->assertSame('512.1235', $actual[0]['strike_price']);
        $this->assertSame('0.0000000001', $actual[0]['gamma']);
        $this->assertSame(0, (int) $actual[1]['volume']);
        $this->assertSame('0.000000', $actual[1]['last_price']);
        foreach (['volume', 'open_interest', 'implied_volatility', 'delta', 'gamma', 'theta', 'vega', 'last_price', 'change', 'change_percent'] as $field) {
            $this->assertNull($actual[2][$field], $field);
        }
    }

    public function test_same_capture_corrections_preserve_identity_and_creation_time(): void
    {
        $capturedAt = Carbon::parse('2026-03-18 16:59:03', 'UTC');
        $ingestor = app(IntradayOptionVolumeIngestor::class);
        $contract = $this->contract(1);
        $ingestor->ingestMany([$contract], 'first-response', $capturedAt);
        $original = DB::table('intraday_option_volumes')->where('symbol', 'BULK')->first();

        Carbon::setTestNow(now()->addSeconds(10));
        $contract['day']['volume'] = 0;
        $contract['day']['close'] = 0;
        $contract['open_interest'] = null;
        $contract['greeks']['delta'] = null;
        $ingestor->ingestMany([$contract], 'corrected-response', $capturedAt);
        $ingestor->ingestMany([$contract], 'corrected-response', $capturedAt);

        $rows = DB::table('intraday_option_volumes')->where('symbol', 'BULK')->get();
        $this->assertCount(1, $rows);
        $actual = $rows->first();
        $this->assertSame($original->id, $actual->id);
        $this->assertSame($original->created_at, $actual->created_at);
        $this->assertSame('2026-03-18 17:00:10', $actual->updated_at);
        $this->assertSame('2026-03-18 16:59:03', $actual->captured_at);
        $this->assertSame('corrected-response', $actual->request_id);
        $this->assertSame(0, (int) $actual->volume);
        $this->assertSame('0.000000', $actual->last_price);
        $this->assertNull($actual->open_interest);
        $this->assertNull($actual->delta);
    }

    public function test_an_older_capture_and_its_retry_do_not_change_a_newer_capture(): void
    {
        $ingestor = app(IntradayOptionVolumeIngestor::class);
        $contract = $this->contract(1);
        $newerAt = Carbon::parse('2026-03-18 16:59:03', 'UTC');
        $olderAt = $newerAt->copy()->subMinute();
        $ingestor->ingestMany([$contract], 'newer-response', $newerAt);
        $newer = $this->storedRows()[0];

        Carbon::setTestNow(now()->addMinute());
        $contract['day']['volume'] = 10;
        $ingestor->ingestMany([$contract], 'older-response', $olderAt);
        $contract['day']['volume'] = 11;
        $ingestor->ingestMany([$contract], 'older-retry', $olderAt);

        $rows = $this->storedRows();
        $this->assertCount(2, $rows);
        $this->assertSame(11, (int) $rows[0]['volume']);
        $this->assertSame($newer, $rows[1]);
    }

    public function test_501_contracts_use_three_upserts_and_no_per_contract_reads(): void
    {
        $sql = [];
        DB::listen(static function (QueryExecuted $query) use (&$sql): void {
            if (str_contains($query->sql, 'intraday_option_volumes')) {
                $sql[] = strtolower($query->sql);
            }
        });
        $contracts = (function (): \Generator {
            for ($index = 1; $index <= 501; $index++) {
                yield $this->contract($index);
            }
        })();

        $written = app(IntradayOptionVolumeIngestor::class)->ingestMany(
            $contracts,
            'chunked-response',
            Carbon::parse('2026-03-18 16:59:03', 'UTC')
        );

        $this->assertSame(501, $written);
        $this->assertCount(3, $sql);
        foreach ($sql as $statement) {
            $this->assertStringStartsWith('insert into', $statement);
            $this->assertStringContainsString('on duplicate key update', $statement);
        }
        $this->assertSame(501, DB::table('intraday_option_volumes')->where('symbol', 'BULK')->count());
    }

    public function test_an_invalid_later_chunk_fails_without_writing_that_chunk(): void
    {
        config()->set('intraday_ingestion.chunk_size', 2);
        $contracts = [$this->contract(1), $this->contract(2), $this->contract(3), $this->contract(4)];
        unset($contracts[3]['details']['ticker']);

        try {
            app(IntradayOptionVolumeIngestor::class)->ingestMany(
                $contracts,
                'invalid-response',
                Carbon::parse('2026-03-18 16:59:03', 'UTC')
            );
            $this->fail('An invalid contract must fail ingestion so the caller withholds publication.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('intraday_option_volumes')->where('symbol', 'BULK')->count());
        $this->assertDatabaseMissing('intraday_option_volumes', [
            'contract_symbol' => $contracts[2]['details']['ticker'],
        ]);
    }

    public function test_the_disabled_bulk_flag_uses_the_compatible_legacy_writer(): void
    {
        config()->set('intraday_ingestion.bulk_enabled', false);
        $capturedAt = Carbon::parse('2026-03-18 16:59:03', 'UTC');
        $contracts = [$this->contract(1), $this->contract(2)];
        $ingestor = app(IntradayOptionVolumeIngestor::class);
        $this->assertSame(2, $ingestor->ingestMany($contracts, 'rollback-response', $capturedAt));
        $expected = $this->storedRows();

        DB::table('intraday_option_volumes')->where('symbol', 'BULK')->delete();
        foreach ($contracts as $contract) {
            $ingestor->ingest($contract, 'rollback-response', $capturedAt);
        }

        $this->assertSame($expected, $this->storedRows());
    }

    public function test_empty_input_writes_no_contract_rows(): void
    {
        $this->assertSame(0, app(IntradayOptionVolumeIngestor::class)->ingestMany([], '', now()));
        $this->assertSame(0, DB::table('intraday_option_volumes')->where('symbol', 'BULK')->count());
    }

    /** @return list<array<string, mixed>> */
    private function storedRows(): array
    {
        return DB::table('intraday_option_volumes')->where('symbol', 'BULK')
            ->orderBy('contract_symbol')->orderBy('captured_at')->get()
            ->map(static function (object $row): array {
                $attributes = (array) $row;
                unset($attributes['id']);

                return $attributes;
            })->all();
    }

    /** @return array<string, mixed> */
    private function contract(int $index): array
    {
        return [
            'underlying_asset' => ['ticker' => 'BULK'],
            'details' => [
                'ticker' => 'O:BULK260320C'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                'contract_type' => $index % 2 === 0 ? 'put' : 'call',
                'expiration_date' => '2026-03-20',
                'strike_price' => 500 + $index,
            ],
            'day' => ['volume' => 75, 'close' => 3.25, 'change' => 0.1, 'change_percent' => 3.17],
            'open_interest' => 500,
            'implied_volatility' => 0.25,
            'greeks' => ['delta' => 0.52, 'gamma' => 0.01, 'theta' => -0.02, 'vega' => 0.2],
        ];
    }
}
