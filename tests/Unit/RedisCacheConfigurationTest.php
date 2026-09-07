<?php

namespace Tests\Unit;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class RedisCacheConfigurationTest extends TestCase
{
    public function test_unset_redis_settings_keep_the_native_local_defaults(): void
    {
        $config = $this->configuration([
            'REDIS_HOST' => null, 'REDIS_PORT' => null, 'REDIS_USERNAME' => null,
            'REDIS_PASSWORD' => null, 'REDIS_URL' => null,
        ]);
        $this->assertSame('127.0.0.1', $config['redis']['cache']['host']);
        $this->assertSame('6379', $config['redis']['cache']['port']);
        $this->assertNull($config['redis']['cache']['password']);
        $this->assertNull($config['redis']['cache']['url']);
    }

    public function test_no_overrides_preserve_existing_cache_default_queue_and_url_configuration(): void
    {
        $config = $this->configuration();
        $this->assertSame('legacy-host', $config['redis']['cache']['host']);
        $this->assertSame('6379', $config['redis']['cache']['port']);
        $this->assertSame('legacy-password', $config['redis']['cache']['password']);
        $this->assertSame('redis://legacy-user:legacy-password@legacy-url-host:6379/0', $config['redis']['cache']['url']);
        $this->assertSame('1', $config['redis']['cache']['database']);
    }

    public function test_explicit_cache_host_port_and_credentials_do_not_move_locks_or_queues(): void
    {
        $before = $this->configuration();
        $after = $this->configuration([
            'REDIS_CACHE_HOST' => 'cache-host', 'REDIS_CACHE_PORT' => '6381',
            'REDIS_CACHE_USERNAME' => 'cache-user', 'REDIS_CACHE_PASSWORD' => 'cache-password',
        ]);
        $this->assertSame($before['redis']['default'], $after['redis']['default']);
        $this->assertSame($before['redis']['queue'], $after['redis']['queue']);
        $this->assertNull($after['redis']['cache']['url']);
        $effective = (new ConfigurationUrlParser)->parseConfiguration($after['redis']['cache']);
        $this->assertSame('cache-host', $effective['host']);
        $this->assertSame('6381', $effective['port']);
        $this->assertSame('cache-user', $effective['username']);
        $this->assertSame('cache-password', $effective['password']);
        $this->assertSame('default', $after['cache']['stores']['redis']['lock_connection']);
        $this->assertSame('cache', $after['cache']['stores']['redis']['connection']);
    }

    public function test_each_explicit_cache_field_suppresses_inherited_url_even_when_null_or_empty(): void
    {
        foreach ([
            'REDIS_CACHE_HOST' => 'cache-host', 'REDIS_CACHE_PORT' => '6381',
            'REDIS_CACHE_USERNAME' => 'null', 'REDIS_CACHE_PASSWORD' => '',
        ] as $key => $value) {
            $this->assertNull($this->configuration([$key => $value])['redis']['cache']['url'], $key);
        }
        $this->assertNull($this->configuration(['REDIS_CACHE_USERNAME' => 'null'])['redis']['cache']['username']);
        $this->assertSame('', $this->configuration(['REDIS_CACHE_PASSWORD' => ''])['redis']['cache']['password']);
    }

    public function test_explicit_cache_url_wins_and_can_also_be_explicitly_disabled(): void
    {
        $config = $this->configuration([
            'REDIS_CACHE_HOST' => 'field-host', 'REDIS_CACHE_PORT' => '6381',
            'REDIS_CACHE_URL' => 'redis://cache-user:cache-password@url-host:16381/2',
        ]);
        $effective = (new ConfigurationUrlParser)->parseConfiguration($config['redis']['cache']);
        $this->assertSame('url-host', $effective['host']);
        $this->assertSame(16381, $effective['port']);
        $this->assertSame('cache-password', $effective['password']);
        $this->assertSame('2', $effective['database']);
        $this->assertNull($this->configuration(['REDIS_CACHE_URL' => 'null'])['redis']['cache']['url']);
    }

    public function test_coordination_is_opt_in_and_keeps_original_endpoint_database_and_prefixes(): void
    {
        $before = $this->configuration(['REDIS_URL' => null]);
        $this->assertFalse($before['cache']['coordination_enabled']);
        $this->assertNull($before['cache']['limiter']);
        $after = $this->configuration([
            'REDIS_URL' => null, 'CACHE_COORDINATION_ENABLED' => 'true',
            'REDIS_CACHE_HOST' => 'payload-host', 'REDIS_CACHE_PORT' => '6381',
            'REDIS_CACHE_USERNAME' => 'payload-user', 'REDIS_CACHE_PASSWORD' => 'payload-password',
            'REDIS_CACHE_DB' => '3',
        ]);
        $this->assertTrue($after['cache']['coordination_enabled']);
        $this->assertSame('coordination', $after['cache']['limiter']);
        $this->assertSame($before['redis']['cache'], $after['redis']['coordination']);
        $this->assertSame('3', $after['redis']['cache']['database']);
        $this->assertSame('1', $after['redis']['coordination']['database']);
        $this->assertSame($before['redis']['options'], $after['redis']['options']);
        $this->assertSame($before['cache']['prefix'], $after['cache']['prefix']);
        $this->assertSame('default', $after['cache']['stores']['coordination']['lock_connection']);
        $this->assertArrayNotHasKey('prefix', $after['cache']['stores']['coordination']);
    }

    public function test_coordination_retains_legacy_url_routing_and_explicit_frozen_database(): void
    {
        $before = $this->configuration();
        $after = $this->configuration([
            'CACHE_COORDINATION_ENABLED' => 'true',
            'REDIS_CACHE_URL' => 'redis://payload:payload-password@payload-host:6381/2',
        ]);
        $parser = new ConfigurationUrlParser;
        $this->assertSame($parser->parseConfiguration($before['redis']['cache']),
            $parser->parseConfiguration($after['redis']['coordination']));
        $this->assertSame('6381', (string) $parser->parseConfiguration($after['redis']['cache'])['port']);
        $config = $this->configuration(['REDIS_URL' => null, 'REDIS_COORDINATION_DB' => '4', 'REDIS_CACHE_DB' => '2']);
        $this->assertSame('4', $config['redis']['coordination']['database']);
    }

    private function configuration(array $overrides = []): array
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        $originalEnv = $property->getValue();
        $originalContainer = Container::getInstance();
        $env = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        foreach (array_replace([
            'APP_NAME' => 'fixture', 'REDIS_HOST' => 'legacy-host', 'REDIS_PORT' => '6379',
            'REDIS_USERNAME' => 'legacy-user', 'REDIS_PASSWORD' => 'legacy-password',
            'REDIS_URL' => 'redis://legacy-user:legacy-password@legacy-url-host:6379/0',
            'REDIS_QUEUE_HOST' => 'queue-host', 'REDIS_QUEUE_PORT' => '6380',
        ], $overrides) as $key => $value) {
            $value === null ? $env->clear($key) : $env->set($key, $value);
        }
        try {
            $property->setValue(null, $env);
            new Application(dirname(__DIR__, 2));
            $config = require __DIR__.'/../../config/database.php';
            $config['cache'] = require __DIR__.'/../../config/cache.php';

            return $config;
        } finally {
            $property->setValue(null, $originalEnv);
            Container::setInstance($originalContainer);
        }
    }
}
