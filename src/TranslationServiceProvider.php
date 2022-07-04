<?php

namespace Karpack\Translations;

use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the translation services.
     *
     * @return void
     */
    public function boot()
    {
        if (App::runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }
}