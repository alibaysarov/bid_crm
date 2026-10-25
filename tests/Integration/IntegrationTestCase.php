<?php

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Modules\PostgresContainer;
use Testcontainers\Wait\WaitForExec;
use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected static ?StartedGenericContainer $postgres = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (static::$postgres === null) {
            $container = new PostgresContainer(
                '17-alpine',
                username: 'bid_crm_test',
                password: 'bid_crm_test',
                database: 'bid_crm_test',
            );
            $container->withWait(new WaitForExec(
                ['pg_isready', '-h', '127.0.0.1', '-U', 'bid_crm_test'],
                timeout: 60_000,
            ));
            static::$postgres = $container->start();

            RefreshDatabaseState::$migrated = false;
        }

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.host', static::$postgres->getHost());
        $app['config']->set('database.connections.pgsql.port', static::$postgres->getFirstMappedPort());
        $app['config']->set('database.connections.pgsql.database', 'bid_crm_test');
        $app['config']->set('database.connections.pgsql.username', 'bid_crm_test');
        $app['config']->set('database.connections.pgsql.password', 'bid_crm_test');

        return $app;
    }

    public static function tearDownAfterClass(): void
    {
        static::$postgres?->stop();
        static::$postgres = null;

        parent::tearDownAfterClass();
    }
}
