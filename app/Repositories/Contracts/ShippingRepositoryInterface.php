<?php

namespace App\Repositories\Contracts;

interface ShippingRepositoryInterface
{
    public function getCarriers();
    public function getRates(array $params);
    public function createLabelByRateId(string $rateId);
    public function voidLabel(string $labelId);
}
