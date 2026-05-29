<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use App\Factory\UserFactory;
use App\Factory\CompanyFactory;
use App\Factory\TaskFactory;

use function Zenstruck\Foundry\faker;

class DefaultFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $companies = CompanyFactory::createMany(5);

        $users = UserFactory::createMany(50, fn() => [
            'company' => faker()->randomElement($companies),
        ]);


        foreach ($users as $user) {
            TaskFactory::createMany(10, [
                'user' => $user,
                'company' => $user->getCompany(),
            ]);
        }

        $manager->flush();
    }
}
