<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\TicketTemplate;
use App\Entity\TicketTemplateSection;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The default ticket PDF template: the text that used to be hardcoded in
 * translations/messages.nl.yaml (pdf.ticket.*). Without a template row the
 * PDF renders no sections, so every environment needs a default template.
 */
class TicketTemplateFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * Load only this one without purging:
     * bin/console doctrine:fixtures:load --append --group=ticket_template
     */
    public static function getGroups(): array
    {
        return ['ticket_template'];
    }

    public function load(ObjectManager $manager): void
    {
        // Idempotent: never create a second default template.
        if ($manager->getRepository(TicketTemplate::class)->findOneBy(['isDefault' => true]) !== null) {
            return;
        }

        $template = new TicketTemplate('Standaard');
        $template->isDefault = true;

        foreach (self::defaultSections() as $position => $content) {
            $section = new TicketTemplateSection();
            $section->position = $position;
            $section->content = $content;
            $template->addSection($section);
        }

        $manager->persist($template);
        $manager->flush();
    }

    /**
     * @return list<string>
     */
    public static function defaultSections(): array
    {
        return [
            '<div><strong>Dit document is jouw originele toegangsbewijs.</strong></div>'
            . '<ul>'
            . '<li>Print je ticket(s) uit en neem deze mee naar de voorstelling. Alle gereserveerde tickets zitten in 1 document, onder elkaar. Elk toegangsbewijs heeft een unieke QR-code en geeft toegang aan één persoon.</li>'
            . '<li>Je kunt de QR-codes ook vanaf je telefoon laten scannen. Zorg er in dat geval (bij meerdere tickets) voor dat je alle QR-codes hebt opgeslagen op je telefoon, en snel kunt tonen bij de toegangscontrole.</li>'
            . '<li>Annuleren van tickets is helaas niet mogelijk. Je mag ze wel aan iemand anders overdragen.</li>'
            . '<li>De zaal en kassa openen één uur voor aanvang van de show.</li>'
            . '<li>Groepen en mensen met een beperking (mits van tevoren gemeld) worden vriendelijk verzocht om uiterlijk 30 minuten vóór aanvang plaats te nemen.</li>'
            . '<li>Bewaar je ticket tot het einde van de voorstelling.</li>'
            . '<li>Kinderen tot en met 2 jaar hebben gratis toegang, maar er moet wel een ticket voor hen gereserveerd worden.</li>'
            . '<li>Kinderwagens en buggy’s zijn niet toegestaan in de zaal; je kunt deze achterlaten in de foyer.</li>'
            . '</ul>',
            '<div><strong>Handige informatie</strong></div>'
            . '<ul>'
            . '<li>Locatie: Jeugdcircus Il Grigio, Bossebaan 5b in Haaren (NB)</li>'
            . '<li>Kaarten worden niet op stoelnummer uitgegeven.</li>'
            . '<li>Kom op tijd als je vooraan en/of naast elkaar wilt zitten.</li>'
            . '</ul>',
            '<div>Wij wensen je een fijne voorstelling!</div>',
        ];
    }
}
