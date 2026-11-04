<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use App\Services\AuditLogger;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Event::listen(Login::class, function ($event) {
            app(AuditLogger::class)->record('auth.login', $event->user, 'User logged in.', [], $event->user->school_id, $event->user->id);
        });

        Event::listen(Logout::class, function ($event) {
            app(AuditLogger::class)->record('auth.logout', $event->user, 'User logged out.', [], $event->user->school_id, $event->user->id);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(\App\Tenancy\TenantContext::class, function () {
            return new \App\Tenancy\TenantContext();
        });

        if ($this->app->isLocal()) {
            $this->app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
        }
        //
    }
}
