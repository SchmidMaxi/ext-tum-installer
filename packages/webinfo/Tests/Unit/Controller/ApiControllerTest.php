<?php

declare(strict_types=1);

namespace Tum\Webinfo\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Tum\Webinfo\Controller\ApiController;
use Tum\Webinfo\Domain\Model\Website;
use Tum\Webinfo\Service\ApiAuthenticationService;
use Tum\Webinfo\Service\WebsiteService;
use TYPO3\CMS\Core\Http\JsonResponse;

class ApiControllerTest extends TestCase
{
    private function createController(
        ?WebsiteService $websiteService = null,
        ?ApiAuthenticationService $authService = null
    ): ApiController {
        return new ApiController(
            $websiteService ?? $this->createStub(WebsiteService::class),
            $authService ?? $this->createStub(ApiAuthenticationService::class)
        );
    }

    private function createRequestStub(string $authHeader, string $body): ServerRequestInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($authHeader);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    #[Test]
    public function createActionReturns401WhenAuthFails(): void
    {
        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn('Invalid API key');

        $controller = $this->createController(authService: $authServiceStub);
        $request = $this->createRequestStub('', '{}');

        $response = $controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function createActionReturns403WhenDomainNotAllowed(): void
    {
        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn(true);
        $authServiceStub->method('validateOriginDomain')->willReturn('Domain not allowed');

        $controller = $this->createController(authService: $authServiceStub);

        $body = json_encode(['domain' => 'test.example.com', 'url' => 'https://test.example.com']);
        $request = $this->createRequestStub('Bearer valid-key', $body);

        $response = $controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function createActionReturns400WhenBodyIsEmpty(): void
    {
        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn(true);
        $authServiceStub->method('validateOriginDomain')->willReturn(true);

        $controller = $this->createController(authService: $authServiceStub);
        $request = $this->createRequestStub('Bearer valid-key', '');

        $response = $controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createActionReturns400WhenRequiredFieldsMissing(): void
    {
        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn(true);
        $authServiceStub->method('validateOriginDomain')->willReturn(true);

        $controller = $this->createController(authService: $authServiceStub);

        $body = json_encode(['navName' => 'test']); // Missing url and domain
        $request = $this->createRequestStub('Bearer valid-key', $body);

        $response = $controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $responseBody = json_decode($response->getBody()->getContents(), true);
        self::assertStringContainsString('Missing required field', $responseBody['error']);
    }

    #[Test]
    public function createActionReturns201OnSuccess(): void
    {
        $websiteStub = $this->createStub(Website::class);
        $websiteStub->method('getUid')->willReturn(42);
        $websiteStub->method('getUrl')->willReturn('https://test.tum.de/project');

        $websiteServiceStub = $this->createStub(WebsiteService::class);
        $websiteServiceStub->method('createFromArray')->willReturn($websiteStub);

        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn(true);
        $authServiceStub->method('validateOriginDomain')->willReturn(true);

        $controller = $this->createController(
            websiteService: $websiteServiceStub,
            authService: $authServiceStub
        );

        $body = json_encode([
            'url' => 'https://test.tum.de/project',
            'domain' => 'test.tum.de',
            'nav_name' => 'project',
            'wid' => 'w00test',
            'setup' => 'Setup1',
        ]);
        $request = $this->createRequestStub('Bearer valid-key', $body);

        $response = $controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(201, $response->getStatusCode());

        $responseBody = json_decode($response->getBody()->getContents(), true);
        self::assertTrue($responseBody['success']);
        self::assertSame(42, $responseBody['data']['uid']);
    }

    #[Test]
    public function listActionReturns401WhenAuthFails(): void
    {
        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn('Missing Authorization header');

        $controller = $this->createController(authService: $authServiceStub);
        $request = $this->createRequestStub('', '');

        $response = $controller->listAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function listActionReturnsWebsitesOnSuccess(): void
    {
        $website1Stub = $this->createStub(Website::class);
        $website1Stub->method('toArray')->willReturn(['uid' => 1, 'url' => 'https://a.tum.de']);

        $website2Stub = $this->createStub(Website::class);
        $website2Stub->method('toArray')->willReturn(['uid' => 2, 'url' => 'https://b.tum.de']);

        $websiteServiceStub = $this->createStub(WebsiteService::class);
        $websiteServiceStub->method('findAll')->willReturn([$website1Stub, $website2Stub]);
        $websiteServiceStub->method('countAll')->willReturn(2);

        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn(true);

        $controller = $this->createController(
            websiteService: $websiteServiceStub,
            authService: $authServiceStub
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer valid-key');
        $request->method('getQueryParams')->willReturn(['search' => '', 'limit' => '10', 'offset' => '0']);

        $response = $controller->listAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $responseBody = json_decode($response->getBody()->getContents(), true);
        self::assertTrue($responseBody['success']);
        self::assertCount(2, $responseBody['data']);
        self::assertSame(2, $responseBody['meta']['total']);
    }

    #[Test]
    public function getActionReturns404WhenWebsiteNotFound(): void
    {
        $websiteServiceStub = $this->createStub(WebsiteService::class);
        $websiteServiceStub->method('findByUid')->willReturn(null);

        $authServiceStub = $this->createStub(ApiAuthenticationService::class);
        $authServiceStub->method('validateRequest')->willReturn(true);

        $controller = $this->createController(
            websiteService: $websiteServiceStub,
            authService: $authServiceStub
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer valid-key');
        $request->method('getQueryParams')->willReturn(['uid' => '999']);

        $response = $controller->getAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
    }
}
