<?php


namespace App\Service\CreditManager;

use Predis\Client;
use Psr\Log\LoggerInterface;

/**
 * @uses sleep()
 */
class CreditManager
{
    private const MAP_KEY_CREDITS_PER_MINUTE = 'cpm';
    private const MAP_KEY_BALANCE_KEY = 'balance_key';

    /**
     * @var array <int, array>
     */
    private array $map = [];
    private Client $redis;
    private ?LoggerInterface $logger;

    public function __construct(Client $redis, ?LoggerInterface $logger = null)
    {
        $this->redis = $redis;
        $this->logger = $logger;
    }

    public function addResource(CreditPerMinuteLimitedObject $object): bool
    {
        $objectId = self::getRegulatedObjectId($object);
        $creditsPerMinute = $object->getCreditsPerMinuteLimit();
        if (isset($this->map[$objectId]) || $creditsPerMinute < 1) {
            return false;
        }

        $this->map[$objectId] = [
            self::MAP_KEY_CREDITS_PER_MINUTE => $creditsPerMinute,
            self::MAP_KEY_BALANCE_KEY => new CreditBalanceKey($object),
        ];
        $this->logger?->info('CreditManager: addResource', [
            'executor' => get_class($object),
            'objectId' => $objectId
        ]);
        return true;
    }

    public static function getRegulatedObjectId(CreditPerMinuteLimitedObject $creditRegulatedObject): string
    {
        return spl_object_hash($creditRegulatedObject);
    }

    public function removeResource(CreditPerMinuteLimitedObject $object): bool
    {
        $objectId = self::getRegulatedObjectId($object);
        if (!isset($this->map[$objectId])) {
            return false;
        }
        $this->logger?->info('CreditManager: removeResource', ['objectId' => $objectId]);
        unset($this->map[$objectId]);
        return true;
    }

    public function spendCredits(CreditPerMinuteLimitedObject $object, int $credits): void
    {
        $this->logger?->info('CreditManager: spendCredits', ['credits' => $credits]);
        if ($credits < 1) {
            return;
        }
        $objectId = self::getRegulatedObjectId($object);
        if (!isset($this->map[$objectId])) {
            throw new CreditManagerException('Resource doesn\'t exist in manager');
        }

        if ($credits > $this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE]) {
            throw new CreditManagerException('Maximum credits exceeded');
        }

        $currentBalance = $this->retrieveBalance($objectId);
        $this->logger?->info('CreditManager: spendCredits', [
            'credits' => $credits,
            'currentBalance' => $currentBalance
        ]);
        if ($currentBalance < $credits) {
            $this->accumulateBalanceUpTo($objectId, $credits);
            $currentBalance = $this->retrieveBalance($objectId);
            $this->logger?->info('CreditManager: spendCredits', [
                'credits' => $credits,
                'currentBalance' => $currentBalance
            ]);
        }

        if ($currentBalance < $credits) {
            throw new CreditManagerException('Can\'t accumulate enough credits.');
        }

        $currentBalance -= $credits;
        $this->saveBalance($objectId, $currentBalance);
    }

    private function retrieveBalance(string $objectId): int
    {
        /** @var CreditBalanceKey $balanceKey */
        $balanceKey = $this->map[$objectId][self::MAP_KEY_BALANCE_KEY];
        if (!$this->redis->exists($balanceKey->getKey())) {
            $this->saveBalance($objectId, $this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE]);
            return $this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE];
        }

        $currentBalance = (int)$this->redis->get($balanceKey->getKey());

        $ttl = $this->redis->ttl($balanceKey->getKey());
        $this->logger?->info('CreditManager: retrieveBalance', [
            'objectId' => $objectId,
            'currentBalance' => $currentBalance,
            'ttl' => $ttl
        ]);
        if (60 - $ttl > 0) {
            $add = (int)floor((60 - $ttl) * $this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE] / 60);
            $currentBalance += $add;
            $this->logger?->info('CreditManager: retrieveBalance', [
                'objectId' => $objectId,
                'currentBalance' => $currentBalance,
                'ttl' => $ttl,
                'add' => $add
            ]);

            if ($currentBalance > $this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE]) {
                $currentBalance = $this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE];
            }
            $this->saveBalance($objectId, $currentBalance);
        }
        $this->logger?->info('CreditManager: retrieveBalance', ['objectId' => $objectId, 'currentBalance' => $currentBalance]);
        return $currentBalance;
    }

    private function saveBalance(string $objectId, int $balance): void
    {
        $this->logger?->info('CreditManager: saveBalance', ['objectId' => $objectId, 'balance' => $balance]);
        /** @var CreditBalanceKey $balanceKey */
        $balanceKey = $this->map[$objectId][self::MAP_KEY_BALANCE_KEY];
        $this->redis->set($balanceKey->getKey(), $balance);
        $this->redis->expire($balanceKey->getKey(), $balanceKey->getTtl());
    }

    private function accumulateBalanceUpTo(string $objectId, int $upToCredits): void
    {
        $currentBalance = $this->retrieveBalance($objectId);
        $creditsToAccumulate = $upToCredits - $currentBalance;
        $this->logger?->info('CreditManager: accumulateBalanceUpTo', [
            'upToCredits' => $upToCredits,
            'currentBalance' => $currentBalance,
            'creditsToAccumulate' => $creditsToAccumulate
        ]);
        if ($creditsToAccumulate < 1) {
            return;
        }
        $seconds = (int)ceil($creditsToAccumulate / ($this->map[$objectId][self::MAP_KEY_CREDITS_PER_MINUTE] / 60));
        if ($seconds > 60) {
            throw new CreditManagerException('Sleep seconds can\'t be more than 60');
        }
        $this->logger?->info('CreditManager: accumulateBalanceUpTo', ['sleep' => $seconds]);
        sleep($seconds);
    }
}