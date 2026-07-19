<?php

namespace Tests\Feature;

use App\Exceptions\ProviderConcurrencyUnavailable;
use App\Support\ProviderConcurrencyLimiter;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SymbolSearchConcurrencyTest extends TestCase
{
    public function test_provider_capacity_timeout_is_retryable_and_not_negative_cached(): void
    {
        config()->set('services.finnhub.api_key', null);
        config()->set('services.massive.key', 'test-key');

        $limiter = Mockery::mock(ProviderConcurrencyLimiter::class);
        $limiter->shouldReceive('withPriority')
            ->once()
            ->andReturnUsing(fn (string $priority, callable $callback): mixed => $callback());
        $limiter->shouldReceive('massive')
            ->with(Mockery::type('callable'), 2)
            ->once()
            ->andThrow(new ProviderConcurrencyUnavailable('test capacity pressure'));
        app()->instance(ProviderConcurrencyLimiter::class, $limiter);

        $this->getJson('/api/symbols?q=ABC')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('items', []);

        $this->assertFalse(Cache::has('sym_search:abc'));
    }
}
