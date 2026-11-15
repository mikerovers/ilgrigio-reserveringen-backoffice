<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Enum\DiscountType;
use App\Enum\OrderSource;
use App\Enum\OrderStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
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
            ->setSearchFields(['orderNumber', 'couponCode', 'billing.firstName', 'billing.lastName', 'billing.email'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices($this->enumChoices(OrderStatus::cases())))
            ->add(ChoiceFilter::new('source')->setChoices($this->enumChoices(OrderSource::cases())))
            ->add(DateTimeFilter::new('createdAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        $money = static fn (string $property): MoneyField => MoneyField::new($property)
            ->useMoneyObject()
            ->setCurrency('EUR');

        yield FormField::addFieldset('Order')->onlyOnDetail();
        yield TextField::new('orderNumber');
        yield VenueDateTimeField::new('createdAt');
        yield ChoiceField::new('status')
            ->setChoices($this->enumChoices(OrderStatus::cases()))
            ->renderAsBadges();
        yield ChoiceField::new('source')
            ->setChoices($this->enumChoices(OrderSource::cases()));
        // The per-order billing name is what staff should see: the Customer row is
        // never changed at checkout, so it may belong to someone else (legacy rows fall back to it).
        yield TextField::new('id', 'Customer')
            ->setSortable(false)
            ->formatValue(fn (mixed $value, Order $order): string => $this->billingName($order));
        yield $money('totalGross')->setLabel('Total');
        yield TextField::new('couponCode')->setLabel('Coupon');

        yield FormField::addFieldset('Customer')->onlyOnDetail();
        yield TextField::new('id', 'Email')
            ->onlyOnDetail()
            ->formatValue(
                static fn (mixed $value, Order $order): ?string => $order->billing->email ?? $order->customer->email,
            );
        yield TextField::new('id', 'Phone')
            ->onlyOnDetail()
            ->formatValue(
                static fn (mixed $value, Order $order): ?string => $order->billing->phoneNumber
                    ?? $order->customer->phoneNumber,
            );
        yield TextField::new('id', 'Company')
            ->onlyOnDetail()
            ->formatValue(
                static fn (mixed $value, Order $order): ?string => $order->billing->companyName
                    ?? $order->customer->companyName,
            );
        yield TextField::new('id', 'City')
            ->onlyOnDetail()
            ->formatValue(
                static fn (mixed $value, Order $order): ?string => $order->billing->city !== ''
                    ? $order->billing->city
                    : $order->customer->city,
            );

        yield FormField::addFieldset('Tickets and amounts')->onlyOnDetail();
        yield AssociationField::new('lineItems')
            ->setLabel('Line items')
            ->onlyOnDetail()
            ->setTemplatePath('admin/order/line_items.html.twig');
        yield $money('subtotalNet')->setLabel('Subtotal (net)')->onlyOnDetail();
        yield $money('tax')->setLabel('Tax')->onlyOnDetail();
        yield $money('discountGross')->setLabel('Discount')->onlyOnDetail();
        yield TextField::new('id', 'Register discount')
            ->onlyOnDetail()
            ->formatValue(fn (mixed $value, Order $order): ?string => $this->manualDiscount($order));
        yield TextField::new('discountReason')->onlyOnDetail();
        yield TextareaField::new('note')->onlyOnDetail();

        yield FormField::addFieldset('Payment')->onlyOnDetail();
        yield TextField::new('id', 'Payment status')
            ->onlyOnDetail()
            ->formatValue(static fn (mixed $value, Order $order): ?string => $order->payment === null
                ? null
                : trim($order->payment->status->value . ' ' . ($order->payment->method ?? '')));
        yield TextField::new('id', 'Mollie payment')
            ->onlyOnDetail()
            ->formatValue(static fn (mixed $value, Order $order): ?string => $order->payment?->molliePaymentId);
        yield VenueDateTimeField::new('paidAt')->onlyOnDetail();
        yield VenueDateTimeField::new('completedAt')->onlyOnDetail();
        yield VenueDateTimeField::new('updatedAt')->onlyOnDetail();
        yield IdField::new('id')->onlyOnDetail();
    }

    /**
     * @param array<\BackedEnum> $cases
     * @return array<string, \BackedEnum>
     */
    private function enumChoices(array $cases): array
    {
        return array_combine(
            array_map(static fn (\BackedEnum $case): string => ucfirst((string) $case->value), $cases),
            $cases,
        );
    }

    private function billingName(Order $order): string
    {
        $name = trim($order->billing->firstName . ' ' . $order->billing->lastName);

        return $name !== ''
            ? $name
            : trim($order->customer->firstName . ' ' . $order->customer->lastName);
    }

    private function manualDiscount(Order $order): ?string
    {
        if ($order->manualDiscountType === null || $order->manualDiscountAmount === null) {
            return null;
        }

        return $order->manualDiscountType === DiscountType::PERCENT
            ? number_format($order->manualDiscountAmount / 100, 2, ',', '.') . '%'
            : '€' . number_format($order->manualDiscountAmount / 100, 2, ',', '.');
    }
}
