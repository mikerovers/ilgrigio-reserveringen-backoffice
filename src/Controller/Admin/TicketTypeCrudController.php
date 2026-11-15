<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\TicketType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Money\Money;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class TicketTypeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly RequestStack $requestStack,
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

    public function configureActions(Actions $actions): Actions
    {
        // The "New" button must carry the event filter, otherwise createEntity()
        // falls back to the latest event and the ticket type lands on the wrong event.
        $eventId = $this->requestStack->getCurrentRequest()?->query->get('event');
        if ($eventId === null) {
            return $actions;
        }

        return $actions->update(
            Crud::PAGE_INDEX,
            Action::NEW,
            fn (Action $action): Action => $action->linkToUrl($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Crud::PAGE_NEW)
                ->set('event', (string) $eventId)
                ->generateUrl()),
        );
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
        yield IdField::new('id')->onlyOnDetail();
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
        yield VenueDateTimeField::new('createdAt')->onlyOnDetail();
        yield VenueDateTimeField::new('updatedAt')->onlyOnDetail();
    }
}
