<?php

declare(strict_types=1);

namespace App\Controller\Scanner;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'scanner_login', host: '%scanner_host%')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('ROLE_CHECK_IN')) {
            return $this->redirectToRoute('scanner_index');
        }

        return $this->render('scanner/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'scanner_logout', host: '%scanner_host%')]
    public function logout(): never
    {
        // Intercepted by the firewall's logout listener before this runs.
        throw new \LogicException('This should never be reached — the logout listener intercepts this route first.');
    }
}
