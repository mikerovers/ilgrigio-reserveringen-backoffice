<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\SitePage;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PageFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $homepage = new SitePage(
            'homepage',
            'Biblio Magico!',
            '31 juli t/m zondag 15 augustus 2027',
            'Dit jaar presenteerden wij onze show Biblio Magico! De Bibliothecaresse en haar assistent probeerden '
            . 'uit alle macht hun bibliotheek netjes op orde te houden. Dat bleek niet makkelijk met een Magisch '
            . 'Boek dat verhalen tot leven bracht.'
        );
        $manager->persist($homepage);

        $manager->flush();
    }
}
