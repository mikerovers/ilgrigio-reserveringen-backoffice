<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\SaleReportResponse;
use App\Repository\OrderRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_sale_report_')]
final class SaleReportApiController extends AbstractController
{
    public function __construct(private OrderRepository $orderRepository)
    {
    }

    /**
     * Tickets sold per year (tickets of completed orders, by order creation year).
     */
    #[Route('/sale-report', name: 'list', methods: ['GET'])]
    #[IsGranted('ROLE_REGISTER')]
    #[Security(name: 'BearerAuth')]
    #[OA\Tag(name: 'Sale report')]
    #[OA\Response(
        response: 200,
        description: 'Tickets sold per year, newest year first',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: SaleReportResponse::class)),
        ),
    )]
    public function list(): JsonResponse
    {
        $report = [];
        foreach ($this->orderRepository->getTicketsSoldPerYear() as $year => $ticketsSold) {
            $report[] = new SaleReportResponse($year, $ticketsSold);
        }

        return $this->json($report);
    }
}
