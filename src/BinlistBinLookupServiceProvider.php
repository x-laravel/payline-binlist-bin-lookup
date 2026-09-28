<?php

namespace XLaravel\Payline\BinLookup\Binlist;

use Illuminate\Support\ServiceProvider;

class BinlistBinLookupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('payline.bin_lookup')->extend('binlist', function ($app, array $config) {
            return new BinlistBinLookup($config);
        });
    }
}
