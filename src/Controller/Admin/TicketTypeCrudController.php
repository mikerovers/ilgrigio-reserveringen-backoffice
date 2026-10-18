<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\TicketType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Money\Money;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class TicketTypeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TicketType::class;
    }

    public function createEntity(string $entityFqcn): TicketType
    {
        $eventRepository = $this->entityManager->getRepository(Event::class);

        $eventId = $this->getContext()?->getRequest()->query->get('event');
        $event = $eventId !== null
            ? $eventRepository->find($eventId)
            : null;
        $event ??= $eventRepository->findOneBy([], ['startsAt' => 'DESC']);

        if ($event === null) {
            throw new \RuntimeException('Cannot create a ticket type without at least one existing event.');
        }

        return new TicketType($event, '', Money::EUR(0));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ticket type')
            ->setEntityLabelInPlural('Ticket types')
            ->setDefaultSort(['event' => 'DESC', 'sortOrder' => 'ASC']);
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $eventId = $this->getContext()?->getRequest()->query->get('event');
        if ($eventId !== null) {
            $queryBuilder
                ->andWhere(sprintf('%s.event = :event', $queryBuilder->getRootAliases()[0]))
                ->setParameter('event', $eventId);
        }

        return $queryBuilder;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('event')
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT);
        yield TextField::new('name');
        yield TextareaField::new('description')->hideOnIndex();
        yield MoneyField::new('price')
            ->useMoneyObject()
            ->setCurrency('EUR');
        yield AssociationField::new('sharedStock');
        yield IntegerField::new('sortOrder')->hideOnIndex();
        yield BooleanField::new('disabled');
        yield TextEditorField::new('disabledReason')
            ->addCssClass('ticket-type-disabled-reason')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
