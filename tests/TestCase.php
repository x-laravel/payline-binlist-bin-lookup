<?php

namespace XLaravel\Payline\BinLookup\Binlist\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Payline\BinLookup\Binlist\BinlistBinLookupServiceProvider;
use XLaravel\Payline\PaylineServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PaylineServiceProvider::class,
            BinlistBinLookupServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');

        $app['config']->set('payline.bin_lookup.default', 'binlist');
    }
}
