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
        $this->app->bind(VneClient::class, fn () => match (config('orio.vne.driver')) {
            'direct' => new DirectVneClient(),
            default => new FakeVneClient(),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->is_admin);
    }
}
