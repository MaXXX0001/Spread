<?php

namespace App\Providers;

use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Allow only the authenticated admin with two-factor authentication set up, in every environment including local.
     * Filament completes a login only after the code, so a signed-in user with a secret has passed it.
     */
    protected function authorization(): void
    {
        Horizon::auth(fn ($request) => $request->user()?->getAppAuthenticationSecret() !== null);
    }
}
