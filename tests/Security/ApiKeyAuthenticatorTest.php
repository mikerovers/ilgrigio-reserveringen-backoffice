<?php

namespace App\Tests\Security;

use App\Security\ApiKeyAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticatorTest extends TestCase
{
    private const VALID_API_KEY = 'test-api-key-12345';
    private ApiKeyAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = new ApiKeyAuthenticator(self::VALID_API_KEY);
    }

    public function testSupportsReturnsTrueWhenApiKeyHeaderPresent(): void
    {
        $request = new Request();
        $request->headers->set('X-API-KEY', self::VALID_API_KEY);

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenApiKeyHeaderMissing(): void
    {
        $request = new Request();

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateSucceedsWithValidApiKey(): void
    {
        $request = new Request();
        $request->headers->set('X-API-KEY', self::VALID_API_KEY);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
        // Test that the passport was created - user loading is handled by Symfony at runtime
    }

    public function testAuthenticateThrowsExceptionWhenApiKeyMissing(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('No API key provided');

        $request = new Request();
        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWithInvalidApiKey(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $request = new Request();
        $request->headers->set('X-API-KEY', 'wrong-api-key');

        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $request = new Request();
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);

        $result = $this->authenticator->onAuthenticationSuccess($request, $token, 'main');

        $this->assertNull($result);
    }

    public function testOnAuthenticationFailureReturnsJsonResponse(): void
    {
        $request = new Request();
        $exception = new AuthenticationException('Test authentication failure');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $content);
        $this->assertArrayHasKey('message', $content);
        $this->assertEquals('Authentication failed', $content['error']);
    }
}
