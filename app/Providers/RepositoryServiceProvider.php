<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\ShippingRepositoryInterface;
use App\Repositories\ShipStationRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register()
    {
            $this->app->bind(
                \App\Repositories\Contracts\ShippingRepositoryInterface::class,
                function ($app) {
                    $driver = config('services.shipping.driver', 'shipstation');
                    if ($driver === 'sendle') {
                        return new \App\Repositories\SendleRepository();
                    }
                    return new \App\Repositories\ShipStationRepository();
                }
            );

    }
}
