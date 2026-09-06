<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$prefix = (string) ($argv[1] ?? '');
$priority = (string) ($argv[2] ?? 'background');
$mode = (string) ($argv[3] ?? 'burst');
$limit = (int) ($argv[4] ?? 6);
[$manager, $redis, $replay, $limiter] = \Tests\Support\ProviderRedisTestEnvironment::configure($app, $prefix, $limit);
echo "READY\n";
fflush(STDOUT);
if ($mode === 'burst') {
    $end = microtime(true) + 10;
    while (! $redis->get($prefix.':start') && microtime(true) < $end) {
        usleep(1000);
    }
}
try {
    $limiter->withPriority($priority, function () use ($limiter, $redis, $prefix, $priority, $mode) {
        return $limiter->massive(function () use ($redis, $prefix, $priority, $mode) {
            $redis->eval(<<<'LUA'
local total = redis.call('HINCRBY', KEYS[1], 'total', 1)
local class = redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
local peak = tonumber(redis.call('HGET', KEYS[1], 'peak') or '0')
local classPeak = tonumber(redis.call('HGET', KEYS[1], ARGV[1] .. ':peak') or '0')
redis.call('HSET', KEYS[1], 'peak', math.max(peak, total), ARGV[1] .. ':peak', math.max(classPeak, class))
redis.call('HINCRBY', KEYS[1], ARGV[1] .. ':completed', 1)
redis.call('EXPIRE', KEYS[1], 60)
LUA, 1, $prefix.':observed', $priority);
            echo "ACQUIRED\n";
            fflush(STDOUT);
            if ($mode === 'die') {
                while (true) {
                    usleep(100000);
                }
            }
            usleep(250000);
            $redis->hincrby($prefix.':observed', 'total', -1);
            $redis->hincrby($prefix.':observed', $priority, -1);

            return 'ok';
        });
    });
    echo "COMPLETE\n";
} catch (\App\Exceptions\ProviderDeferred $exception) {
    echo 'DEFERRED:'.$exception->reason."\n";
}
