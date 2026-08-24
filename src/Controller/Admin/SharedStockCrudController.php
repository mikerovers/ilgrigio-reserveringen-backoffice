<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SharedStock;
use App\Enum\StockStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class SharedStockCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SharedStock::class;
    }

    public function createEntity(string $entityFqcn): SharedStock
    {
        return new SharedStock('');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Shared stock')
            ->setEntityLabelInPlural('Shared stock')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield IntegerField::new('stockQuantity')
            ->setHelp('Leave empty for unlimited stock.');
        yield ChoiceField::new('stockStatus')
            ->setChoices(StockStatus::cases())
            ->renderAsBadges([
                StockStatus::IN_STOCK->value => 'success',
                StockStatus::OUT_OF_STOCK->value => 'danger',
            ])
            ->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
