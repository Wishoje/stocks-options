<?php

namespace App\Console\Commands;

use App\Services\SwingResearch\SwingResearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CollectSwingResearch extends Command
{
    protected $signature = 'swing-research:collect {--limit=6 : Symbols per bounded batch}';

    protected $description = 'Collect the private paired swing experiment from retained EOD evidence';

    public function handle(SwingResearchService $service): int
    {
        if (! config('swing_research.enabled')) {
            $this->info('Swing research collection is disabled.');

            return self::SUCCESS;
        }
        $id = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower(trim((string) config('setup_study.owner_email')))])->value('id');
        if (! $id) {
            $this->info('No swing-research owner account exists in this environment.');

            return self::SUCCESS;
        }
        $result = $service->collect((int) $id, max(1, min(20, (int) $this->option('limit'))));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['prepared'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
