<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\SalesStatisticsService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(
    routePath: '/',
    routeName: 'admin',
    routeOptions: ['host' => '%admin_host%'],
)]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly SalesStatisticsService $salesStatisticsService,
    ) {
    }

    public function index(): Response
    {
        $years = $this->salesStatisticsService->getYearsWithSales();
        rsort($years);

        $totalsByYear = [];
        foreach ($years as $year) {
            $totalsByYear[$year] = $this->salesStatisticsService->getAnnualTicketsSold($year);
        }

        return $this->render('admin/dashboard.html.twig', [
            'totals_by_year' => $totalsByYear,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Backoffice')
            ->setFaviconPath('images/favicon.ico');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addAssetMapperEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Orders', 'fa fa-shopping-cart');
        yield MenuItem::linkTo(OrderCrudController::class, 'Orders', 'fa fa-list')
            ->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Events', 'fa fa-book');
        yield MenuItem::linkTo(EventCrudController::class, 'Events', 'fa fa-calendar')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(EventCategoryCrudController::class, 'Event categories', 'fa fa-tags')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(SharedStockCrudController::class, 'Shared stock', 'fa fa-cubes')
            ->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Promotions', 'fa fa-percent');
        yield MenuItem::linkTo(CouponCrudController::class, 'Coupons', 'fa fa-ticket')
            ->setPermission('ROLE_ADMIN');
    }
}
