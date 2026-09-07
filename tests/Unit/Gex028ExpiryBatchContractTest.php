<?php

namespace Tests\Unit;

use App\Http\Controllers\ExpiryController;
use App\Support\ExpiryPressureBatch;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Gex028ExpiryBatchContractTest extends TestCase
{
    public static function indexCandidates(): array
    {
        return [
            'absent' => [[], null],
            'wrong-prefix' => [[['index_name' => 'date_first', 'indexed_columns' => 'data_date,expiration_id']], null],
            'expression-prefix' => [[['index_name' => 'expression_first', 'indexed_columns' => '#expression,expiration_id,data_date']], null],
            'unsafe-name' => [[['index_name' => 'bad); SELECT 1', 'indexed_columns' => 'expiration_id,data_date']], null],
            'safe-renamed-index' => [[['index_name' => 'custom_chain_index', 'indexed_columns' => 'expiration_id,data_date,option_type,strike']], 'custom_chain_index'],
        ];
    }

    #[DataProvider('indexCandidates')]
    public function test_capability_only_accepts_safe_installed_expiration_date_indexes(array $rows, ?string $expected): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('join', 'where', 'select', 'selectRaw', 'groupBy', 'orderBy')->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(collect(array_map(static fn (array $row): object => (object) $row, $rows)));
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('table')->with('information_schema.statistics as s')->once()->andReturn($query);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('gex_profile_test');
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $reader = new class extends ExpiryPressureBatch
        {
            public function inspectIndex(): ?string
            {
                return $this->compatibleSpotIndex();
            }
        };
        $this->assertSame($expected, $reader->inspectIndex());
    }

    public function test_disabled_is_the_default_and_empty_input_does_not_query_any_database(): void
    {
        $this->assertFalse(config('expiry_performance.batch_enabled'));
        DB::shouldReceive('connection')->never();
        DB::shouldReceive('table')->never();
        DB::shouldReceive('query')->never();
        $this->assertSame([], (new ExpiryPressureBatch)->read([], 3, '2026-09-04'));
        foreach ([false, true] as $enabled) {
            config()->set('expiry_performance.batch_enabled', $enabled);
            $response = (new ExpiryController)->pressureBatch(Request::create('/api/expiry-pressure/batch', 'GET', ['symbols' => []]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('{"items":[]}', $response->getContent());
        }
    }
}
