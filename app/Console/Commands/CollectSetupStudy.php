<?php

namespace App\Console\Commands;

use App\Services\SetupStudy\SetupStudyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CollectSetupStudy extends Command
{
    protected $signature = 'setup-study:collect {--limit=12 : Symbols per bounded batch} {--backfill : Read up to fourteen retained intraday days}';

    protected $description = 'Keep compact owner-only setup study measurements from already recorded evidence';

    public function handle(SetupStudyService $service): int
    {
        $id = DB::table('users')->where('email', config('setup_study.owner_email'))->value('id');
        if (! $id) {
            $this->info('No setup-study owner account exists in this environment.');

            return self::SUCCESS;
        }
        $result = $service->collect((int) $id, max(1, min(20, (int) $this->option('limit'))), (bool) $this->option('backfill'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['prepared'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
