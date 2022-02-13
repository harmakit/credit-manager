<?php

namespace App\Service\CreditManager;

class CreditBalanceKey
{
    public const KEY_PREFIX = 'credit:balance:';

    protected string $key;
    protected int $ttl = 60;

    public function __construct(CreditPerMinuteLimitedObject $object)
    {
        $this->key = sprintf(
            '%s:%s:%s',
            self::KEY_PREFIX,
            md5(get_class($object)),
            CreditManager::getRegulatedObjectId($object)
        );
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }
}