<?php

declare(strict_types=1);

namespace Tests;

use Core\Tenant\Boot;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            Boot::class,
        ];
    }

    /**
     * Run this package's migrations against the test database.
     *
     * Boot::boot() calls loadMigrationsFrom, which registers the path for a
     * host application to migrate — it does not migrate anything itself. Under
     * Testbench that left the suite with Testbench's skeleton tables and none
     * of this package's, so every test touching a workspace failed on a missing
     * table rather than on the behaviour it was written for.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Migrations');
    }
}
