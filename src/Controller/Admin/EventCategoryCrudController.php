<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EventCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class EventCategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return EventCategory::class;
    }

    public function createEntity(string $entityFqcn): EventCategory
    {
        return new EventCategory('');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Event category')
            ->setEntityLabelInPlural('Event categories')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('name');
        yield TextField::new('slug')
            ->hideOnForm()
            ->setHelp('Generated automatically from the name.');
        yield TextField::new('id', 'Events')
            ->setSortable(false)
            ->onlyOnIndex()
            ->formatValue(
                static fn (mixed $value, EventCategory $category): string => (string) $category->events->count(),
            );
        yield VenueDateTimeField::new('createdAt')->onlyOnDetail();
        yield VenueDateTimeField::new('updatedAt')->onlyOnDetail();
    }
}
