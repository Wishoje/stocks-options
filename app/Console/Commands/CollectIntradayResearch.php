<?php

namespace App\Console\Commands;

use App\Services\IntradayResearch\IntradayResearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CollectIntradayResearch extends Command
{
    protected $signature = 'intraday-research:collect {--limit=100 : Symbols per bounded batch}';

    protected $description = 'Collect the private paired intraday experiment from retained evidence';

    public function handle(IntradayResearchService $service): int
    {
        if (! config('intraday_research.enabled')) {
            $this->info('Intraday research collection is disabled.');

            return self::SUCCESS;
        }
        if (! IntradayResearchService::collectionAllowed()) {
            $this->info('Outside the intraday research collection window.');

            return self::SUCCESS;
        }
        $id = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower(trim((string) config('setup_study.owner_email')))])->value('id');
        if (! $id) {
            $this->info('No intraday-research owner account exists in this environment.');

            return self::SUCCESS;
        }
        $result = $service->collect((int) $id, max(1, min(200, (int) $this->option('limit'))));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['prepared'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
