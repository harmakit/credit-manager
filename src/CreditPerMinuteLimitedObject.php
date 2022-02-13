<?php

namespace App\Service\CreditManager;

interface CreditPerMinuteLimitedObject
{
    public function getCreditsPerMinuteLimit(): int;
}