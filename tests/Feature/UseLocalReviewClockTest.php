<?php

namespace Tests\Feature;

use App\Http\Middleware\UseLocalReviewClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class UseLocalReviewClockTest extends TestCase
{
    private const REVIEW_NOW = '2026-02-10T12:00:00-05:00';

    private string $originalEnvironment;

    private mixed $originalClock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app->environment();
        $this->originalClock = Carbon::getTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow($this->originalClock);
        $this->app['env'] = $this->originalEnvironment;

        parent::tearDown();
    }

    public function test_local_opt_in_applies_during_the_request_and_restores_the_previous_clock(): void
    {
        $this->app['env'] = 'local';
        config()->set('ui_review.now', self::REVIEW_NOW);

        $previousClock = Carbon::parse('2031-04-05T09:30:00+00:00');
        Carbon::setTestNow($previousClock);
        $observed = null;

        Route::get('/_test/ui-review-clock', function () use (&$observed) {
            $observed = now('America/New_York')->toIso8601String();

            return response()->noContent();
        })->middleware(UseLocalReviewClock::class);

        $this->get('/_test/ui-review-clock')->assertNoContent();

        $this->assertSame(self::REVIEW_NOW, $observed);
        $this->assertSame($previousClock->toIso8601String(), Carbon::getTestNow()?->toIso8601String());
    }

    public function test_blank_local_setting_leaves_the_existing_clock_unchanged(): void
    {
        $this->app['env'] = 'local';
        config()->set('ui_review.now', '   ');

        $previousClock = Carbon::parse('2032-05-06T10:15:00+00:00');
        Carbon::setTestNow($previousClock);
        $observed = null;

        Route::get('/_test/ui-review-clock-blank', function () use (&$observed) {
            $observed = Carbon::now()->toIso8601String();

            return response()->noContent();
        })->middleware(UseLocalReviewClock::class);

        $this->get('/_test/ui-review-clock-blank')->assertNoContent();

        $this->assertSame($previousClock->toIso8601String(), $observed);
        $this->assertSame($previousClock->toIso8601String(), Carbon::getTestNow()?->toIso8601String());
    }

    public function test_non_local_environments_ignore_an_opt_in_value(): void
    {
        config()->set('ui_review.now', self::REVIEW_NOW);
        $previousClock = Carbon::parse('2033-06-07T11:45:00+00:00');
        $observed = null;

        Route::get('/_test/ui-review-clock-non-local', function () use (&$observed) {
            $observed = Carbon::now()->toIso8601String();

            return response()->noContent();
        })->middleware(UseLocalReviewClock::class);

        foreach (['testing', 'production'] as $environment) {
            $this->app['env'] = $environment;
            Carbon::setTestNow($previousClock);
            $observed = null;

            $this->get('/_test/ui-review-clock-non-local')->assertNoContent();

            $this->assertSame($previousClock->toIso8601String(), $observed, $environment);
            $this->assertSame($previousClock->toIso8601String(), Carbon::getTestNow()?->toIso8601String(), $environment);
        }
    }

    public function test_previous_clock_is_restored_when_the_request_throws(): void
    {
        $this->app['env'] = 'local';
        config()->set('ui_review.now', self::REVIEW_NOW);

        $previousClock = Carbon::parse('2034-07-08T12:00:00+00:00');
        Carbon::setTestNow($previousClock);
        $observed = null;

        Route::get('/_test/ui-review-clock-failure', function () use (&$observed) {
            $observed = now('America/New_York')->toIso8601String();

            throw new RuntimeException('review clock probe');
        })->middleware(UseLocalReviewClock::class);

        $this->withoutExceptionHandling();

        try {
            $this->get('/_test/ui-review-clock-failure');
            $this->fail('The probe request did not throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('review clock probe', $exception->getMessage());
        }

        $this->assertSame(self::REVIEW_NOW, $observed);
        $this->assertSame($previousClock->toIso8601String(), Carbon::getTestNow()?->toIso8601String());
    }

    public function test_historical_review_clock_does_not_expire_web_session_cookies(): void
    {
        $this->app['env'] = 'local';
        config()->set('ui_review.now', self::REVIEW_NOW);
        config()->set('session.expire_on_close', false);
        Carbon::setTestNow();

        $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = $response->headers->getCookies();
        $cookieNames = array_map(
            static fn ($cookie): string => $cookie->getName(),
            $cookies,
        );

        $this->assertContains('XSRF-TOKEN', $cookieNames);
        $this->assertContains(config('session.cookie'), $cookieNames);

        foreach ($cookies as $cookie) {
            $this->assertGreaterThan(
                time(),
                $cookie->getExpiresTime(),
                $cookie->getName().' should use the real session clock.',
            );
        }
    }
}
