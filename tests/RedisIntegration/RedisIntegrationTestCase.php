<?php

namespace Tests\RedisIntegration;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Modules\RedisContainer;
use Tests\Integration\IntegrationTestCase;

abstract class RedisIntegrationTestCase extends IntegrationTestCase
{
    protected static ?StartedGenericContainer $redis = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (static::$redis === null) {
            static::$redis = (new RedisContainer('7-alpine'))->start();
        }

        $app['config']->set('database.redis.default.host', static::$redis->getHost());
        $app['config']->set('database.redis.default.port', static::$redis->getFirstMappedPort());
        $app['config']->set('database.redis.default.database', 15);
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis.retry_after', 90);

        Redis::purge('default');

        return $app;
    }

    public static function tearDownAfterClass(): void
    {
        static::$redis?->stop();
        static::$redis = null;

        parent::tearDownAfterClass();
    }
}
