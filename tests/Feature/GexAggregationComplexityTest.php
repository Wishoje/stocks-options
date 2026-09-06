<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\OptionChainData;
use App\Support\EodSnapshotSelector;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class GexAggregationComplexityTest extends TestCase
{
    private ?ConnectionResolverInterface $originalResolver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalResolver = Model::getConnectionResolver();
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $connection = Mockery::mock(Connection::class);
        $query = Mockery::mock(Builder::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);
        $connection->shouldReceive('query')->andReturn($query);
        $query->shouldReceive('from')->with('option_chain_data')->andReturnSelf();
        $query->shouldReceive('whereIn')->with('expiration_id', Mockery::type('array'))->andReturnSelf();
        $query->shouldReceive('where')->withArgs(
            static fn ($column, $operator, $value): bool => $column === 'data_date'
                && in_array($operator, ['<', '<='], true) && is_string($value),
        )->andReturnSelf();
        // Two MAX lookups per aggregation. No query is sent to any database.
        $query->shouldReceive('max')->with('data_date')->times(4)->andReturnNull();
        Model::setConnectionResolver($resolver);
        Cache::shouldReceive('get')->with('gamma_strength:SPY:2026-09-04')->twice()->andReturnNull();
    }

    protected function tearDown(): void
    {
        if ($this->originalResolver !== null) {
            Model::setConnectionResolver($this->originalResolver);
        }
        GexAttributeReadCounter::reset();

        parent::tearDown();
    }

    public function test_contract_reads_do_not_multiply_when_the_same_chain_has_more_distinct_strikes(): void
    {
        // Both fixtures contain 960 contracts. Only the distribution changes:
        // 24 strikes x 20 expirations x 2 sides versus 240 x 2 x 2.
        $compact = $this->aggregate(24, 20);
        $wide = $this->aggregate(240, 2);

        foreach ([$compact, $wide] as $result) {
            $payload = $result['payload'];
            $this->assertCount($result['strikes'], $payload['strike_data']);
            $this->assertSame(9600, $payload['call_open_interest_total']);
            $this->assertSame(4800, $payload['put_open_interest_total']);
            $this->assertSame(2400, $payload['call_volume_total']);
            $this->assertSame(1440, $payload['put_volume_total']);
            $this->assertSame(14400, $payload['total_oi_delta']);
            $this->assertSame(3840, $payload['total_volume_delta']);
            $this->assertLessThanOrEqual(
                8 * $result['contracts'],
                $result['strike_attribute_reads'],
                'Current-chain strike reads must be bounded by contract count, not strikes multiplied by contracts.',
            );
        }
        $this->assertLessThanOrEqual(
            $compact['strike_attribute_reads'] * 2 + $wide['contracts'],
            $wide['strike_attribute_reads'],
            'A tenfold increase in distinct strikes must not trigger tenfold rescanning of the same number of contracts.',
        );
    }

    private function aggregate(int $strikes, int $expirations): array
    {
        $rows = [];
        foreach (range(1, $expirations) as $expirationId) {
            foreach (range(0, $strikes - 1) as $strikeIndex) {
                foreach (['call', 'put'] as $side) {
                    $model = new GexAttributeReadCounter;
                    $model->setRawAttributes([
                        'expiration_id' => $expirationId,
                        'data_date' => '2026-09-04',
                        'strike' => number_format(100 + $strikeIndex * 0.5, 2, '.', ''),
                        'option_type' => $side,
                        'open_interest' => $side === 'call' ? 20 : 10,
                        'volume' => $side === 'call' ? 5 : 3,
                        'gamma' => '0.010000',
                        'underlying_price' => '100.000000',
                    ]);
                    $rows[] = $model;
                }
            }
        }
        $expirationIds = range(1, $expirations);
        $dates = ['2026-09-18'];
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $selector->shouldReceive('selectedRows')->once()
            ->with($expirationIds, ['option_chain_data.*'], '2026-09-04')
            ->andReturn(new Collection($rows));
        $this->app->instance(EodSnapshotSelector::class, $selector);
        GexAttributeReadCounter::reset();

        $payload = (new ReflectionMethod(GexController::class, 'buildGexPayload'))->invoke(
            new GexController,
            'SPY', '30d', $dates, ['30d' => $dates], $expirationIds, '2026-09-04',
        );

        return [
            'payload' => $payload,
            'strikes' => $strikes,
            'contracts' => count($rows),
            'strike_attribute_reads' => GexAttributeReadCounter::$strikeReads,
        ];
    }
}

class GexAttributeReadCounter extends OptionChainData
{
    public static int $strikeReads = 0;

    public static function reset(): void
    {
        self::$strikeReads = 0;
    }

    public function getAttribute($key)
    {
        if ($key === 'strike') {
            self::$strikeReads++;
        }

        return parent::getAttribute($key);
    }
}
