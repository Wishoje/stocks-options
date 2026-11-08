<?php

namespace Tests\Feature;

use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Support\IntradayRefreshDispatcher;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductUniverseCollectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('watchlists')) {
            Schema::create('watchlists', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('symbol', 10);
                $table->timestamps();
            });
        }
        DB::table('watchlists')->delete();
        config()->set('product_universe.enabled', true);
        config()->set('product_universe.symbols', ['AVGO']);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_configured_symbol_is_collected_without_a_personal_watchlist(): void
    {
        $this->assertSame(['AVGO'], app(IntradayRefreshDispatcher::class)->watchlistSymbols());

        $this->artisan('watchlist:preload')->assertSuccessful();

        Bus::assertBatched(function (PendingBatch $batch): bool {
            if ($batch->jobs->count() !== 1) {
                return false;
            }
            $head = $batch->jobs->first();
            $chain = collect($head->chained)->map(static fn (string $serialized): object => unserialize($serialized));

            return $head instanceof PricesBackfillJob
                && $head->symbols === ['AVGO']
                && $chain->last() instanceof PublishEodCacheVersionJob;
        });
        $this->assertDatabaseCount('watchlists', 0);
        Http::assertNothingSent();
    }

    public function test_quote_collection_includes_the_configured_symbol_without_an_owner_row(): void
    {
        config()->set('quote_refresh.enabled', false);

        $this->artisan('prices:refresh', ['--source' => 'watchlist'])->assertSuccessful();

        Bus::assertDispatched(FetchUnderlyingQuotesJob::class, static fn (FetchUnderlyingQuotesJob $job): bool => $job->symbols === ['AVGO']);
        Bus::assertDispatchedTimes(FetchUnderlyingQuotesJob::class, 1);
        $this->assertDatabaseCount('watchlists', 0);
        Http::assertNothingSent();
    }
}
