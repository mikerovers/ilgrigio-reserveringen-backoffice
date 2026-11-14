<?php

declare(strict_types=1);

namespace App\Controller\Register;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'register_login', host: '%register_host%')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('ROLE_REGISTER')) {
            return $this->redirectToRoute('register_index');
        }

        return $this->render('register/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'register_logout', host: '%register_host%')]
    public function logout(): never
    {
        // Intercepted by the firewall's logout listener before this runs.
        throw new \LogicException('This should never be reached — the logout listener intercepts this route first.');
    }
}
