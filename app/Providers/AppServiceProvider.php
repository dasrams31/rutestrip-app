<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin activity log (sign-ins, uploads, moderation, ...): App\Support\ActivitySubscriber.
        Event::subscribe(\App\Support\ActivitySubscriber::class);
    }
}
