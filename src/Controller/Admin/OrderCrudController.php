<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Enum\OrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Order')
            ->setEntityLabelInPlural('Orders')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield TextField::new('orderNumber');
        yield AssociationField::new('customer');
        yield ChoiceField::new('status')
            ->setChoices(array_combine(
                array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()),
                OrderStatus::cases(),
            ))
            ->renderAsBadges();
        yield MoneyField::new('totalGross')
            ->useMoneyObject()
            ->setCurrency('EUR');
        yield MoneyField::new('discountGross')
            ->useMoneyObject()
            ->setCurrency('EUR')
            ->hideOnIndex();
        yield TextField::new('couponCode')
            ->setLabel('Coupon code')
            ->hideOnIndex();
        yield AssociationField::new('coupon')
            ->setLabel('Coupon')
            ->hideOnIndex();
        yield AssociationField::new('lineItems')
            ->setLabel('Line items')
            ->onlyOnDetail()
            ->setTemplatePath('admin/order/line_items.html.twig');
        yield DateTimeField::new('paidAt')->hideOnIndex();
        yield DateTimeField::new('completedAt')->hideOnIndex();
        yield DateTimeField::new('createdAt');
        yield DateTimeField::new('updatedAt')->hideOnIndex();
    }
}
