<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Account\Domain\Account;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AccountFixture extends Fixture
{
    public const ACCOUNT_EUR_1 = 'account-eur-1';
    public const ACCOUNT_EUR_2 = 'account-eur-2';
    public const ACCOUNT_USD_1 = 'account-usd-1';

    public function load(ObjectManager $manager): void
    {
        $account1 = new Account('EUR', 100_00); // €100.00
        $manager->persist($account1);
        $this->addReference(self::ACCOUNT_EUR_1, $account1);

        $account2 = new Account('EUR', 50_00); // €50.00
        $manager->persist($account2);
        $this->addReference(self::ACCOUNT_EUR_2, $account2);

        $account3 = new Account('USD', 200_00); // $200.00
        $manager->persist($account3);
        $this->addReference(self::ACCOUNT_USD_1, $account3);

        $manager->flush();
    }
}
