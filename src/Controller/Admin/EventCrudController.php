<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
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
        yield TextareaField::new('description')->hideOnIndex();
        yield DateTimeField::new('startsAt');
        yield DateTimeField::new('endsAt');
        yield TextField::new('location')->hideOnIndex();
        yield AssociationField::new('categories');
        yield AssociationField::new('ticketTypes')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
