# credit-manager

Simple library for tracking and calling credit-per-minute limited services using predis/predis

## Installation

```sh
composer require harmakit/credit-manager
```

## Usage

Inherit your class, which calls some credit-per-minute limited function, from `CreditPerMinuteLimitedObject` interface.

```php
class SomeLimitedExecutor implements CreditPerMinuteLimitedObject
{
    private CreditManager $creditManager;

    public function __construct(CreditManager $creditManager) {
        $this->creditManager = $creditManager;
        $this->creditManager->addResource($this); // Call this to add your class object to the CreditManager's list of services
    }

    public function __destruct()
    {
        $this->creditManager->removeResource($this);
    }

    public function getCreditsPerMinuteLimit(): int
    {
        return 100; // Your value of credits which are allowed to spend over 1 minute
    }
}
```

Now you are ready to manage your calls!

```php
public function callSomething($callCost = 25): void
{
    $this->creditManager->spendCredits($this, $callCost); // Call this method when you want to spend credits
    // CreditManager will wait using sleep() function to accumulate enough credits to perform required call cost if needed
    doCallSomething();
}
```