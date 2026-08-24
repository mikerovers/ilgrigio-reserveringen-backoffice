<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
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
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Il Grigio Backoffice');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkTo(EventCategoryCrudController::class, 'Event categories', 'fa fa-tags')
            ->setPermission('ROLE_ADMIN');
    }
}
