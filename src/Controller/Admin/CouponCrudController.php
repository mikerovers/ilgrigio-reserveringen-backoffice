<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Coupon;
use App\Enum\DiscountType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CouponCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Coupon::class;
    }

    public function createEntity(string $entityFqcn): Coupon
    {
        return new Coupon('', DiscountType::PERCENT, 0);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Coupon')
            ->setEntityLabelInPlural('Coupons')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code')
            ->setHelp('Unique coupon code (case-sensitive).');
        yield ChoiceField::new('discountType')
            ->setChoices([
                'Percentage (basis points)' => DiscountType::PERCENT,
                'Fixed cart (cents)' => DiscountType::FIXED_CART,
            ])
            ->renderAsBadges([
                DiscountType::PERCENT->value => 'success',
                DiscountType::FIXED_CART->value => 'primary',
            ]);
        yield IntegerField::new('amount')
            ->setHelp('Percentage: basis points (1000 = 10%). Fixed: cents (500 = €5,00).');
        yield TextareaField::new('description')->hideOnIndex();
        yield BooleanField::new('active');
        yield IntegerField::new('usedCount')
            ->setLabel('Times used')
            ->hideOnForm()
            ->setDisabled();
        yield DateTimeField::new('expiresAt')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
