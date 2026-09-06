<?php

namespace Tests\Unit;

use App\Support\PolygonClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class IntradayProviderClassificationTest extends TestCase
{
    public static function classifications(): array
    {
        return ['configured heavy' => [true, 51], 'normal' => [false, 50]];
    }

    #[DataProvider('classifications')]
    public function test_provider_page_budget_uses_the_shared_heavy_symbol_configuration(bool $heavy, int $expectedPages): void
    {
        config()->set('queue_lanes.intraday_heavy_symbols', $heavy ? ['IWM'] : []);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('services.massive.key', 'fixture-only');
        config()->set('services.massive.mode', 'bearer');
        config()->set('services.massive.base', 'https://api.massive.com');
        $pages = 0;
        Http::preventStrayRequests();
        Http::fake(function () use (&$pages) {
            $pages++;
            $payload = ['results' => [['ticker' => 'fixture-'.$pages]], 'request_id' => 'fixture'];
            if ($pages < 51) { $payload['next_url'] = 'https://api.massive.com/v3/snapshot/options/IWM?cursor='.$pages; }

            return Http::response($payload, 200);
        });
        $result = (new ReflectionMethod(PolygonClient::class, 'snapshotChainFromMassive'))
            ->invoke(app(PolygonClient::class), 'IWM', '2026-09-11');

        $this->assertSame($expectedPages, $pages);
        $this->assertSame($heavy, $result['complete']);
        $this->assertCount($expectedPages, $result['results']);
    }
}
