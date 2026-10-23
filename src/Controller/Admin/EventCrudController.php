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
            ->setDefaultSort(['startsAt' => 'DESC']);
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
            ->add(Crud::PAGE_INDEX, $manageTicketTypes)
            ->add(Crud::PAGE_DETAIL, $manageTicketTypes);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('slug')
            ->hideOnForm()
            ->setHelp('Generated automatically from the name.');
        yield TextField::new('description')
            ->hideOnIndex()
            ->setFormTypeOption('attr.maxlength', 255)
            ->setHelp('Maximaal 255 tekens.');
        yield VenueDateTimeField::new('startsAt');
        yield VenueDateTimeField::new('doorsOpenAt')
            ->setHelp('Moment waarop de zaal opent. Wordt op het ticket en de website getoond '
                . 'als "Zaal open"; alleen het tijdstip wanneer dit op dezelfde dag is als de '
                . 'voorstelling. Laat leeg wanneer nog niet bekend.');
        yield VenueDateTimeField::new('endsAt');
        yield VenueDateTimeField::new('soldFrom')
            ->setHelp('Start of the ticket sale period. Leave empty for no start limit.');
        yield VenueDateTimeField::new('soldUntil')
            ->setHelp('End of the ticket sale period. Leave empty for no end limit.');
        yield TextField::new('location')->hideOnIndex();
        yield AssociationField::new('categories');
        yield AssociationField::new('displayStock')
            ->setHelp('Shared stock whose quantity drives the low stock and sold out display for this event. '
                . 'Leave empty to use all of this event\'s stock.');
        yield AssociationField::new('ticketTypes')
            ->onlyOnDetail();
        yield VenueDateTimeField::new('createdAt')->hideOnForm();
        yield VenueDateTimeField::new('updatedAt')->hideOnForm();
    }
}
