<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class EventCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public function createEntity(string $entityFqcn): Event
    {
        return new Event('', new \DateTimeImmutable());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Event')
            ->setEntityLabelInPlural('Events')
            ->setDefaultSort(['startsAt' => 'DESC'])
            ->setSearchFields(['name', 'location']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $manageTicketTypes = Action::new('manageTicketTypes', 'Ticket types', 'fa fa-ticket')
            ->linkToUrl(fn (Event $event): string => $this->adminUrlGenerator
                ->setController(TicketTypeCrudController::class)
                ->setAction(Crud::PAGE_INDEX)
                ->set('event', (string) $event->id)
                ->generateUrl());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $manageTicketTypes)
            ->add(Crud::PAGE_DETAIL, $manageTicketTypes);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('name');
        yield TextField::new('slug')
            ->hideOnForm()
            ->hideOnIndex()
            ->setHelp('Generated automatically from the name.');
        yield TextField::new('description')
            ->hideOnIndex()
            ->setFormTypeOption('attr.maxlength', 255)
            ->setHelp('Maximaal 255 tekens.');
        yield VenueDateTimeField::new('startsAt');
        yield VenueDateTimeField::new('doorsOpenAt')
            ->hideOnIndex()
            ->setHelp('Moment waarop de zaal opent. Wordt op het ticket en de website getoond '
                . 'als "Zaal open"; alleen het tijdstip wanneer dit op dezelfde dag is als de '
                . 'voorstelling. Laat leeg wanneer nog niet bekend.');
        yield VenueDateTimeField::new('endsAt')->hideOnIndex();
        yield VenueDateTimeField::new('soldFrom')
            ->hideOnIndex()
            ->setHelp('Start of the ticket sale period. Leave empty for no start limit.');
        yield VenueDateTimeField::new('soldUntil')
            ->hideOnIndex()
            ->setHelp('End of the ticket sale period. Leave empty for no end limit.');
        yield TextField::new('id', 'Ticket sale')
            ->setSortable(false)
            ->hideOnForm()
            ->formatValue(static fn (mixed $value, Event $event): string => $event->isOnSale(new \DateTimeImmutable())
                ? 'On sale'
                : 'Closed');
        yield TextField::new('id', 'Stock')
            ->setSortable(false)
            ->hideOnForm()
            ->formatValue(static function (mixed $value, Event $event): string {
                $quantity = $event->displayStockQuantity();

                return $quantity === null ? 'Unlimited' : (string) $quantity;
            });
        yield TextField::new('location')->hideOnIndex();
        yield AssociationField::new('categories');
        yield AssociationField::new('displayStock')
            ->setHelp('Shared stock whose quantity drives the low stock and sold out display for this event. '
                . 'Leave empty to use all of this event\'s stock.');
        yield AssociationField::new('ticketTemplate')
            ->hideOnIndex()
            ->setHelp('Text below the date on the PDF ticket. Leave empty to use the default template.');
        yield AssociationField::new('ticketTypes')
            ->onlyOnDetail();
        yield VenueDateTimeField::new('createdAt')->onlyOnDetail();
        yield VenueDateTimeField::new('updatedAt')->onlyOnDetail();
    }
}
