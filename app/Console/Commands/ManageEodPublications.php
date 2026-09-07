<?php

namespace App\Console\Commands;

use App\Support\EodLegacyPublicationInventory;
use App\Support\EodPublicationRepository;
use App\Support\RedisCacheTopology;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ManageEodPublications extends Command
{
    protected $signature = 'gex:publications
        {--inventory : Read-only bounded inventory of legacy Redis publication heads}
        {--prepare : Import exact legacy heads and initialize the durable cutover floor once}
        {--writers-drained : Attest that producers, old workers and delayed legacy finalizers are drained}
        {--mirror : Repair Redis compatibility mirrors from committed durable heads}
        {--topology : Include redacted Redis process-isolation checks}';

    protected $description = 'Inspect durable EOD publications; writes require explicit prepare or mirror options';

    public function handle(EodPublicationRepository $repository, EodLegacyPublicationInventory $inventory): int
    {
        try {
            $actions = array_filter(['inventory', 'prepare', 'mirror'], fn ($action): bool => (bool) $this->option($action));
            if (count($actions) > 1) {
                throw new RuntimeException('Choose only one of inventory, prepare or mirror.');
            }
            if ($this->option('prepare') && (! $this->option('writers-drained')
                || EodPublicationRepository::writesEnabled() || (bool) config('eod_publications.read_enabled'))) {
                throw new RuntimeException('Prepare requires --writers-drained and both publication switches disabled.');
            }
            $out = ['write_enabled' => EodPublicationRepository::writesEnabled(),
                'read_enabled' => EodPublicationRepository::readsEnabled()];
            if ($this->option('inventory') || $this->option('prepare')) {
                $first = $inventory->capture();
                $out['legacy_inventory'] = ['count' => $first['count'], 'sha256' => $first['sha256']];
                if ($this->option('prepare')) {
                    $second = $inventory->capture();
                    if (! hash_equals($first['sha256'], $second['sha256']) || $first['count'] !== $second['count']) {
                        throw new RuntimeException('Legacy heads changed during inventory; writers are not drained.');
                    }
                    $prepared = $repository->prepare($second['heads'], (int) floor(microtime(true) * 1000000));
                    $out['heads_imported'] = $prepared['heads_imported'];
                }
            }
            $tables = Schema::hasTable('eod_cache_publication_state') && Schema::hasTable('eod_cache_publications');
            $state = $tables ? DB::table('eod_cache_publication_state')->where('id', 1)->first() : null;
            $out['schema_present'] = $tables;
            $out['prepared'] = $state !== null;
            $out['heads'] = $tables ? DB::table('eod_cache_publications')->count() : 0;
            if ($this->option('mirror')) {
                if ($state === null || ! EodPublicationRepository::writesEnabled()) {
                    throw new RuntimeException('Mirror repair requires a prepared registry and durable writers enabled.');
                }
                $count = 0;
                DB::table('eod_cache_publications')->select('symbol')->distinct()->orderBy('symbol')
                    ->chunk(250, function ($rows) use ($repository, &$count): void {
                        $repository->mirror($rows->pluck('symbol')->all());
                        $count += $rows->count();
                    });
                $out['symbols_mirrored'] = $count;
            }
            if ($this->option('topology')) {
                $out['topology'] = app(RedisCacheTopology::class)->inspect();
            }
            $this->line(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $this->option('topology') && ! ($out['topology']['checks_passed'] ?? false)
                ? self::FAILURE : self::SUCCESS;
        } catch (RuntimeException $exception) {
            // Only our fixed operator-facing errors are printed. SQL/Redis
            // exceptions may contain connection details or publication tokens.
            if (get_class($exception) === RuntimeException::class) {
                $this->error($exception->getMessage());
            } else {
                $this->error('Publication inspection failed; inspect private application diagnostics. No fallback was attempted.');
            }

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Publication operation failed; inspect private application diagnostics before retrying.');

            return self::FAILURE;
        }
    }
}
