<?php

namespace Php\LaravelObfuscator;

use Illuminate\Support\ServiceProvider;
use Php\LaravelObfuscator\Commands\CodeDeobfuscateCommand;
use Php\LaravelObfuscator\Commands\CodeObfuscateCommand;

final class LaravelObfuscatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind; commands instantiate the codec/obfuscator directly.
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CodeObfuscateCommand::class,
                CodeDeobfuscateCommand::class,
            ]);
        }
    }
}

