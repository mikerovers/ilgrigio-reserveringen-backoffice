<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\CustomerAccountController;
use App\Entity\Customer;
use App\Service\LoginCodeService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** Logs a customer in by checking the emailed one-time code (POST /inloggen/code). */
final class EmailCodeAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private LoginCodeService $loginCodeService,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'limiter.login_code_verify')]
        private RateLimiterFactory $verifyLimiter,
        #[Autowire('%env(bool:CUSTOMER_LOGIN_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->enabled
            && $request->isMethod('POST')
            && $request->getPathInfo() === $this->urlGenerator->generate('customer_login_code');
    }

    public function authenticate(Request $request): Passport
    {
        $email = (string) $request->getSession()->get(CustomerAccountController::SESSION_EMAIL, '');
        $code = (string) $request->request->get('code', '');

        if ($email === '') {
            throw new CustomUserMessageAuthenticationException('Je sessie is verlopen. Vraag opnieuw een code aan.');
        }

        $limit = $this->verifyLimiter->create(LoginCodeService::normaliseEmail($email))->consume();
        if (!$limit->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('Te veel pogingen. Probeer het later opnieuw.');
        }

        $customer = $this->loginCodeService->verify($email, $code);
        if ($customer === null) {
            throw new CustomUserMessageAuthenticationException('Deze code is ongeldig of verlopen.');
        }

        return new SelfValidatingPassport(
            new UserBadge($customer->getUserIdentifier(), static fn (): Customer => $customer),
            [new CsrfTokenBadge('customer_login_code', (string) $request->request->get('_csrf_token', ''))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $request->getSession()->remove(CustomerAccountController::SESSION_EMAIL);

        return new RedirectResponse($this->urlGenerator->generate('customer_orders'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('login_error', $exception->getMessageKey());
        }

        return new RedirectResponse($this->urlGenerator->generate('customer_login_code'));
    }

    /** Anonymous visitors of /mijn-tickets are sent to the login form. */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('customer_login'));
    }
}
