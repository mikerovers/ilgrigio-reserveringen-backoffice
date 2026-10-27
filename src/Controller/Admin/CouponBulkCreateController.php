<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\BulkCouponRequest;
use App\Entity\Coupon;
use App\Enum\DiscountType;
use App\Repository\CouponRepository;
use App\Service\CouponCodeGenerator;
use App\Service\VenueTime;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/coupon-bulk-create', name: 'coupon_bulk_create')]
class CouponBulkCreateController extends AbstractController
{
    public function __construct(
        private readonly CouponCodeGenerator $codeGenerator,
        private readonly CouponRepository $couponRepository,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[AdminRoute(options: ['methods' => ['GET']])]
    public function form(): Response
    {
        return $this->render('admin/coupon/bulk_create_form.html.twig');
    }

    #[AdminRoute(path: '/generate-codes', name: '_generate_codes', options: ['methods' => ['POST']])]
    public function generateCodes(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_bulk_coupon', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $prefix = (string) $request->request->get('prefix', '');
        $amount = (int) $request->request->get('amount', 0);
        $quantity = (int) $request->request->get('quantity', 0);

        if ($prefix === '' || $amount < 1 || $quantity < 1) {
            return new JsonResponse(
                ['error' => 'Prefix, amount, and quantity are required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $codes = $this->codeGenerator->generate($prefix, $amount, $quantity);

        return new JsonResponse(['codes' => $codes]);
    }

    #[AdminRoute(path: '/save', name: '_save', options: ['methods' => ['POST']])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_bulk_coupon', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $bulkRequest = $this->mapRequestToDto($request);
        $violations = $this->validator->validate($bulkRequest);

        if (\count($violations) > 0) {
            return $this->render('admin/coupon/bulk_create_form.html.twig', [
                'violations' => $violations,
                'bulk_request' => $bulkRequest,
            ]);
        }

        $codes = $bulkRequest->parseManualCodes();
        $codeErrors = $this->findCodeErrors($codes);

        if ($codeErrors !== []) {
            return $this->render('admin/coupon/bulk_create_form.html.twig', [
                'bulk_request' => $bulkRequest,
                'code_errors' => $codeErrors,
            ]);
        }

        $storedAmount = $this->toStoredAmount($bulkRequest->discountType, $bulkRequest->amount);

        foreach ($codes as $code) {
            $coupon = new Coupon($code, $bulkRequest->discountType, $storedAmount);
            $coupon->description = $bulkRequest->description;
            $coupon->active = $bulkRequest->active;
            $coupon->expiresAt = $bulkRequest->expiresAt;
            $coupon->usageLimit = $bulkRequest->usageLimit;
            $this->entityManager->persist($coupon);
        }

        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('%d coupon code(s) created.', \count($codes)));

        $couponIndexUrl = $this->adminUrlGenerator
            ->setController(CouponCrudController::class)
            ->setAction(Crud::PAGE_INDEX)
            ->generateUrl();

        return $this->redirect($couponIndexUrl);
    }

    private function mapRequestToDto(Request $request): BulkCouponRequest
    {
        $expiresAtValue = $request->request->get('expiresAt', '');
        $expiresAt = null;

        if (\is_string($expiresAtValue) && $expiresAtValue !== '') {
            $expiresAt = VenueTime::fromLocal($expiresAtValue);
        }

        $usageLimitValue = trim((string) $request->request->get('usageLimit', ''));
        $usageLimit = $usageLimitValue === '' ? null : (int) $usageLimitValue;

        $discountType = DiscountType::from(
            (string) $request->request->get('discountType', DiscountType::PERCENT->value),
        );

        return new BulkCouponRequest(
            discountType: $discountType,
            amount: (int) $request->request->get('amount', 0),
            expiresAt: $expiresAt,
            active: $request->request->getBoolean('active', true),
            description: $request->request->get('description') ?: null,
            manualCodes: (string) $request->request->get('manualCodes', ''),
            usageLimit: $usageLimit,
        );
    }

    /**
     * @param string[] $codes
     *
     * @return array<string, string>
     */
    private function findCodeErrors(array $codes): array
    {
        $errors = [];
        $seen = [];

        foreach ($codes as $code) {
            if (isset($seen[$code])) {
                $errors[$code] = 'Duplicate code within this batch.';
                continue;
            }

            $seen[$code] = true;

            if ($this->couponRepository->findOneByCode($code) !== null) {
                $errors[$code] = 'This code already exists.';
            }
        }

        return $errors;
    }

    private function toStoredAmount(DiscountType $discountType, int $displayAmount): int
    {
        return match ($discountType) {
            DiscountType::PERCENT => $displayAmount * 100,
            DiscountType::FIXED_CART => $displayAmount * 100,
            DiscountType::BY_TICKET => $displayAmount,
        };
    }
}
