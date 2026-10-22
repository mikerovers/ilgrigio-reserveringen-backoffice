<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Coupon;
use App\Enum\DiscountType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CouponCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

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

    public function configureActions(Actions $actions): Actions
    {
        $bulkCreate = Action::new('bulkCreate', 'Bulk create', 'fa fa-layer-group')
            ->linkToUrl(fn (): string => $this->urlGenerator->generate('admin_coupon_bulk_create'))
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $bulkCreate);
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
                'By ticket (highest-priced tickets free)' => DiscountType::BY_TICKET,
            ])
            ->renderAsBadges([
                DiscountType::PERCENT->value => 'success',
                DiscountType::FIXED_CART->value => 'primary',
                DiscountType::BY_TICKET->value => 'warning',
            ]);
        yield IntegerField::new('amount')
            ->setHelp(
                'Percentage: basis points (100 = 1%, 1000 = 10%, 10000 = 100%). '
                . 'Fixed: cents (500 = €5,00). '
                . 'By ticket: number of highest-priced tickets made free.',
            )
            ->formatValue(function (mixed $value, Coupon $coupon): string {
                if ($coupon->discountType === DiscountType::FIXED_CART) {
                    $formatter = new DecimalMoneyFormatter(new ISOCurrencies());

                    return '€' . $formatter->format(Money::EUR($coupon->amount));
                }

                if ($coupon->discountType === DiscountType::BY_TICKET) {
                    return sprintf('%d ticket(s)', $coupon->amount);
                }

                return number_format($coupon->amount / 100, 2) . '%';
            });
        yield IntegerField::new('usageLimit')
            ->setHelp('Max. non-failed orders that may use this code. Empty = unlimited.');
        yield TextareaField::new('description')->hideOnIndex();
        yield BooleanField::new('active')
            ->renderAsSwitch(false);
        yield IntegerField::new('usedCount')
            ->setLabel('Times used')
            ->hideOnForm()
            ->hideOnIndex()
            ->setDisabled();
        yield VenueDateTimeField::new('expiresAt')->hideOnIndex();
        yield VenueDateTimeField::new('createdAt')->hideOnForm();
        yield VenueDateTimeField::new('updatedAt')->hideOnForm();
    }
}
