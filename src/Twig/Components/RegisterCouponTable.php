<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\CouponListQuery;
use App\Entity\Coupon;
use App\Enum\DiscountType;
use App\Repository\CouponRepository;
use App\Service\MoneyService;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Pagination\LiveComponent\ComponentWithPaginationTrait;
use Symfony\UX\Pagination\Pagination;
use Symfony\UX\Pagination\PaginationBuilder;
use Symfony\UX\Pagination\PaginatorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Paginated, searchable coupon table of the register app. Staff can invalidate
 * a coupon (active = false); like the API this is one-way.
 *
 * Live components are reachable on every host under /_components, so the class
 * carries its own IsGranted: the register firewall alone does not protect it.
 */
#[AsLiveComponent(name: 'RegisterCouponTable', template: 'components/register/coupon_table.html.twig')]
#[IsGranted('ROLE_REGISTER')]
final class RegisterCouponTable
{
    use DefaultActionTrait;
    use ComponentWithPaginationTrait;

    public const PER_PAGE = 10;
    private const MAX_SEARCH_LENGTH = 64;

    #[LiveProp(writable: true, onUpdated: 'resetPage')]
    public string $search = '';

    /** Id of the coupon waiting for an invalidate confirmation. */
    #[LiveProp]
    public ?string $deactivateFor = null;

    #[LiveProp]
    public ?string $flash = null;

    #[LiveProp]
    public bool $flashIsError = false;

    public function __construct(
        private CouponRepository $couponRepository,
        private EntityManagerInterface $entityManager,
        private MoneyService $moneyService,
        private PaginatorInterface $paginator,
        private ClockInterface $clock,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function getCouponsPagination(): Pagination
    {
        $pagination = $this->getPagination();
        if ($pagination->isOutOfRange() && ($pagination->getTotalPages() ?? 0) >= 1) {
            $this->page = (int) $pagination->getTotalPages();
            $pagination = $this->getPagination();
        }

        return $pagination;
    }

    protected function createPagination(): PaginationBuilder
    {
        $search = mb_substr(trim($this->search), 0, self::MAX_SEARCH_LENGTH);
        $query = new CouponListQuery(search: $search === '' ? null : $search);

        return $this->paginator
            ->query($this->couponRepository->createListQueryBuilder($query))
            ->perPage(self::PER_PAGE);
    }

    public function getDeactivateCoupon(): ?Coupon
    {
        return $this->deactivateFor === null ? null : $this->findCoupon($this->deactivateFor);
    }

    public function isExpired(Coupon $coupon): bool
    {
        return $coupon->expiresAt !== null && $coupon->expiresAt <= $this->clock->now();
    }

    /** Human readable discount: "10%", "€ 12,00" or "2 tickets gratis". */
    public function formatAmount(Coupon $coupon): string
    {
        return match ($coupon->discountType) {
            DiscountType::PERCENT => rtrim(rtrim(number_format($coupon->amount / 100, 2, ',', ''), '0'), ',') . '%',
            DiscountType::FIXED_CART => '€ ' . number_format($this->moneyService->toFloat(Money::EUR($coupon->amount)), 2, ',', '.'),
            DiscountType::BY_TICKET => $coupon->amount . ($coupon->amount === 1 ? ' ticket gratis' : ' tickets gratis'),
        };
    }

    #[LiveAction]
    public function askDeactivate(#[LiveArg] string $id): void
    {
        $this->flash = null;
        $coupon = $this->findCoupon($id);
        $this->deactivateFor = $coupon !== null && $coupon->active ? (string) $coupon->id : null;
    }

    #[LiveAction]
    public function confirmDeactivate(): void
    {
        $coupon = $this->getDeactivateCoupon();
        $this->deactivateFor = null;
        if ($coupon === null) {
            return;
        }

        if ($coupon->active) {
            $coupon->active = false;
            $this->entityManager->flush();
            $this->logger->info('Register coupon deactivated', [
                'code' => $coupon->code,
                'deactivated_by' => $this->security->getUser()?->getUserIdentifier(),
            ]);
        }

        $this->flash = sprintf('Kortingscode %s is ongeldig gemaakt.', $coupon->code);
        $this->flashIsError = false;
    }

    #[LiveAction]
    public function closeModal(): void
    {
        $this->deactivateFor = null;
    }

    private function findCoupon(string $id): ?Coupon
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->couponRepository->find($id);
    }
}
