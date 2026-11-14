<?php

declare(strict_types=1);

namespace App\Controller\Register;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(host: '%register_host%')]
#[IsGranted('ROLE_REGISTER')]
final class RegisterController extends AbstractController
{
    #[Route('/', name: 'register_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('register/index.html.twig', ['operator' => $this->operator()]);
    }

    #[Route('/bestellingen', name: 'register_orders', methods: ['GET'])]
    public function orders(): Response
    {
        return $this->render('register/orders.html.twig', ['operator' => $this->operator()]);
    }

    #[Route('/kortingscodes', name: 'register_coupons', methods: ['GET'])]
    public function coupons(): Response
    {
        return $this->render('register/coupons.html.twig', ['operator' => $this->operator()]);
    }

    private function operator(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->name : null;
    }
}
