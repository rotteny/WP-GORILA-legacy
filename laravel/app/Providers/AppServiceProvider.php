<?php

namespace App\Providers;

use App\Services\AntiBanThrottle;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AntiBanThrottle e singleton porque sua config nao muda em runtime
        // e ele e consumido tanto pelo job de envio quanto por testes.
        $this->app->singleton(AntiBanThrottle::class, static fn () => AntiBanThrottle::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
