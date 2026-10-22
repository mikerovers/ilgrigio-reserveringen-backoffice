<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use Symfony\Component\HttpFoundation\RequestStack;

final class OrderConfirmationEmailContextFactory
{
    public function __construct(
        private MoneyService $moneyService,
        private RequestStack $requestStack,
        private SecurePdfStorageService $securePdfStorageService,
        private string $appBaseUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Order $order, string $downloadToken): array
    {
        $request = $this->requestStack->getCurrentRequest();
        return [
            'order_number' => $order->orderNumber,
            'order_date' => $order->createdAt ?? new \DateTimeImmutable(),
            'customer_name' => trim($order->billing->firstName . ' ' . $order->billing->lastName),
            'customer_email' => $order->billing->email,
            'download_url' => $this->securePdfStorageService->generateDownloadUrl(
                $request,
                $downloadToken,
                $this->appBaseUrl,
            ),
            'order_subtotal' => $this->moneyService->format($order->totalGross->add($order->discountGross)),
            'order_total' => $this->moneyService->format($order->totalGross),
            'total_tax' => $this->moneyService->format($order->tax),
            'order_data' => $this->buildOrderView($order),
        ];
    }

    /**
     * View model matching the keys the email templates read off `order_data`.
     *
     * @return array<string, mixed>
     */
    private function buildOrderView(Order $order): array
    {
        $lineItems = [];
        foreach ($order->lineItems as $lineItem) {
            $lineItems[] = [
                'name' => $lineItem->name,
                'quantity' => $lineItem->quantity,
                'total' => $this->moneyService->format($lineItem->lineGross),
            ];
        }

        $couponLines = [];
        if ($order->couponCode !== null && !$order->discountGross->isZero()) {
            $couponLines[] = [
                'code' => $order->couponCode,
                'discount' => $this->moneyService->format($order->discountGross),
            ];
        }

        return [
            'line_items' => $lineItems,
            'coupon_lines' => $couponLines,
            'payment_method_title' => $order->payment?->method ?? 'iDEAL',
        ];
    }
}
