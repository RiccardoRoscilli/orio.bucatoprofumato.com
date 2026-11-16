<?php

namespace App\Providers;

use App\Services\Vne\DirectVneClient;
use App\Services\Vne\FakeVneClient;
use App\Services\Vne\VneClient;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Services\Pos\CloudPos::class, fn () => config('orio.pos.driver') === 'multisafepay'
            ? new \App\Services\Pos\MultiSafepayPos()
            : new \App\Services\Pos\FakePos());
        $this->app->bind(VneClient::class, fn () => match (config('orio.vne.driver')) {
            'direct' => new DirectVneClient(),
            'browser' => \App\Services\Vne\BrowserVneClient::fromRequest($this->app->bound('request') ? $this->app['request'] : null),
            default => new FakeVneClient(),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->is_admin);

        // Configurazione salvata dall'admin sopra al .env (non dentro la cache di config:cache)
        if (! in_array($_SERVER['argv'][1] ?? null, ['config:cache', 'optimize'], true)) {
            \App\Support\Settings::apply();
        }
    }
}
