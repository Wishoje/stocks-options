<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFeature;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SkewBucketReviewClockTest extends TestCase
{
    private string $originalEnvironment;

    private bool $createdIvSkewTable = false;

    private bool $databaseTransactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app->environment();

        if (! Schema::hasTable('iv_skew')) {
            Schema::create('iv_skew', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 12)->index();
                $table->date('data_date')->index();
                $table->date('exp_date')->index();
                $table->decimal('iv_put_25d', 8, 6)->nullable();
                $table->decimal('iv_call_25d', 8, 6)->nullable();
                $table->decimal('skew_pc', 8, 6)->nullable();
                $table->decimal('curvature', 10, 8)->nullable();
                $table->decimal('skew_pc_dod', 8, 6)->nullable();
                $table->decimal('curvature_dod', 10, 8)->nullable();
                $table->timestamps();
                $table->unique(['symbol', 'data_date', 'exp_date']);
            });

            $this->createdIvSkewTable = true;
        }

        DB::connection()->beginTransaction();
        $this->databaseTransactionStarted = true;
        DB::table('iv_skew')->delete();
        // Keep the review-clock middleware active while the dedicated route
        // boundary test owns authentication and entitlement assertions.
        $this->withoutMiddleware([Authenticate::class, EnsureFeature::class]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnvironment;

        if ($this->databaseTransactionStarted && DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        if ($this->createdIvSkewTable) {
            Schema::dropIfExists('iv_skew');
        }

        parent::tearDown();
    }

    public function test_summary_bucket_uses_the_local_review_clock(): void
    {
        $this->app['env'] = 'local';
        // This is already February 11 in UTC, while New York is still
        // February 10. A UTC-based bucket would incorrectly choose 02-18.
        config()->set('ui_review.now', '2026-02-10T23:30:00-05:00');

        DB::table('iv_skew')->insert([
            $this->row('2026-02-10'),
            $this->row('2026-02-17'),
            $this->row('2026-02-18'),
            $this->row('2026-04-30'),
        ]);

        $this->getJson('/api/iv/skew/by-bucket?symbol=SPY&days=7')
            ->assertOk()
            ->assertJsonPath('exp_date', '2026-02-17');
    }

    private function row(string $expiry): array
    {
        return [
            'symbol' => 'SPY',
            'data_date' => '2026-02-09',
            'exp_date' => $expiry,
            'iv_put_25d' => 0.25,
            'iv_call_25d' => 0.20,
            'skew_pc' => 0.05,
            'curvature' => 0.01,
            'created_at' => '2026-02-09 16:30:00',
            'updated_at' => '2026-02-09 16:30:00',
        ];
    }
}
