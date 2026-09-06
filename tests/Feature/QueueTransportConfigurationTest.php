<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

class QueueTransportConfigurationTest extends TestCase
{
    public function test_transport_switch_does_not_move_cache_locks_or_legacy_drain_connections(): void
    {
        $env = Env::getRepository();
        $old = $env->get('REDIS_QUEUE_CONNECTION');
        $before = require base_path('config/database.php');
        try {
            $env->set('REDIS_QUEUE_CONNECTION', 'queue');
            $queue = require base_path('config/queue.php');
            $database = require base_path('config/database.php');
            $this->assertSame('queue', $queue['connections']['redis']['connection']);
            $this->assertSame('queue', $queue['connections']['redis-long']['connection']);
            $this->assertSame('default', $queue['connections']['redis-legacy']['connection']);
            $this->assertSame('default', $queue['connections']['redis-legacy-long']['connection']);
            // A transport switch must preserve configured ports, including
            // explicitly isolated test ports. It must not assume defaults.
            $this->assertSame($before['redis']['queue']['port'], $database['redis']['queue']['port']);
            $this->assertSame($before['redis']['default']['port'], $database['redis']['default']['port']);
            $this->assertSame($database['redis']['default']['host'], $database['redis']['cache']['host']);
            $env->set('REDIS_QUEUE_CONNECTION', 'default');
            $rollback = require base_path('config/queue.php');
            $this->assertSame('default', $rollback['connections']['redis']['connection']);
            $this->assertSame('default', $rollback['connections']['redis-long']['connection']);
        } finally {
            $old === null ? $env->clear('REDIS_QUEUE_CONNECTION') : $env->set('REDIS_QUEUE_CONNECTION', $old);
        }
    }
}
