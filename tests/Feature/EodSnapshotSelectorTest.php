<?php

namespace Tests\Feature;

use App\Support\EodSnapshotSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EodSnapshotSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_latest_balanced_snapshot_over_newer_broken_snapshot(): void
    {
        config(['services.massive.eod_min_side_strike_ratio' => 0.5]);

        $expirationId = $this->createExpiration('SPY', '2026-03-20');

        $this->insertChainRows($expirationId, '2026-03-17', [
            ['call', 100], ['call', 101], ['put', 100], ['put', 101],
        ]);
        $this->insertChainRows($expirationId, '2026-03-18', [
            ['call', 100], ['call', 101], ['call', 102], ['put', 100],
        ]);

        $selector = app(EodSnapshotSelector::class);

        $selected = $selector->selectedDateRows([$expirationId], '2026-03-18')->first();
        $summary = $selector->summary([$expirationId], '2026-03-18')->get($expirationId);

        $this->assertSame('2026-03-17', $selected->max_date);
        $this->assertSame('2026-03-18', $summary['latest_any_date']);
        $this->assertSame('2026-03-17', $summary['latest_balanced_date']);
        $this->assertSame('2026-03-17', $summary['selected_date']);
        $this->assertEqualsWithDelta(0.333, (float) $summary['latest_any_side_ratio'], 0.001);
    }

    public function test_it_falls_back_to_latest_any_when_no_balanced_snapshot_exists(): void
    {
        config(['services.massive.eod_min_side_strike_ratio' => 0.8]);

        $expirationId = $this->createExpiration('QQQ', '2026-04-17');

        $this->insertChainRows($expirationId, '2026-03-17', [
            ['call', 100], ['call', 101], ['put', 100],
        ]);
        $this->insertChainRows($expirationId, '2026-03-18', [
            ['call', 100], ['call', 101], ['call', 102], ['put', 100],
        ]);

        $selector = app(EodSnapshotSelector::class);

        $selected = $selector->selectedDateRows([$expirationId], '2026-03-18')->first();
        $summary = $selector->summary([$expirationId], '2026-03-18')->get($expirationId);

        $this->assertSame('2026-03-18', $selected->max_date);
        $this->assertNull($summary['latest_balanced_date']);
        $this->assertSame('2026-03-18', $summary['selected_date']);
    }

    protected function createExpiration(string $symbol, string $expirationDate): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => $expirationDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<int,array{0:string,1:int|float}> $rows
     */
    protected function insertChainRows(int $expirationId, string $dataDate, array $rows): void
    {
        foreach ($rows as [$optionType, $strike]) {
            DB::table('option_chain_data')->insert([
                'expiration_id' => $expirationId,
                'data_date' => $dataDate,
                'option_type' => $optionType,
                'strike' => $strike,
                'open_interest' => 100,
                'volume' => 10,
                'gamma' => 0.01,
                'delta' => $optionType === 'call' ? 0.5 : -0.5,
                'iv' => 0.25,
                'underlying_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
