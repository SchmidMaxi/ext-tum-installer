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
    private ApiController $controller;
    private WebsiteService $websiteServiceMock;
    private ApiAuthenticationService $authServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->websiteServiceMock = $this->createMock(WebsiteService::class);
        $this->authServiceMock = $this->createMock(ApiAuthenticationService::class);

        $this->controller = new ApiController(
            $this->websiteServiceMock,
            $this->authServiceMock
        );
    }

    #[Test]
    public function createActionReturns401WhenAuthFails(): void
    {
        $request = $this->createRequestMock('', '{}');

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn('Invalid API key');

        $response = $this->controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function createActionReturns403WhenDomainNotAllowed(): void
    {
        $body = json_encode(['domain' => 'test.example.com', 'url' => 'https://test.example.com']);
        $request = $this->createRequestMock('Bearer valid-key', $body);

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn(true);

        $this->authServiceMock
            ->method('validateOriginDomain')
            ->willReturn('Domain not allowed');

        $response = $this->controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function createActionReturns400WhenBodyIsEmpty(): void
    {
        $request = $this->createRequestMock('Bearer valid-key', '');

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn(true);

        $this->authServiceMock
            ->method('validateOriginDomain')
            ->willReturn(true);

        $response = $this->controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createActionReturns400WhenRequiredFieldsMissing(): void
    {
        $body = json_encode(['navName' => 'test']); // Missing url and domain
        $request = $this->createRequestMock('Bearer valid-key', $body);

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn(true);

        $this->authServiceMock
            ->method('validateOriginDomain')
            ->willReturn(true);

        $response = $this->controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $responseBody = json_decode($response->getBody()->getContents(), true);
        self::assertStringContainsString('Missing required field', $responseBody['error']);
    }

    #[Test]
    public function createActionReturns201OnSuccess(): void
    {
        $body = json_encode([
            'url' => 'https://test.tum.de/project',
            'domain' => 'test.tum.de',
            'nav_name' => 'project',
            'wid' => 'w00test',
            'setup' => 'Setup1',
        ]);
        $request = $this->createRequestMock('Bearer valid-key', $body);

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn(true);

        $this->authServiceMock
            ->method('validateOriginDomain')
            ->willReturn(true);

        $website = $this->createMock(Website::class);
        $website->method('getUid')->willReturn(42);
        $website->method('getUrl')->willReturn('https://test.tum.de/project');

        $this->websiteServiceMock
            ->method('createFromArray')
            ->willReturn($website);

        $response = $this->controller->createAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(201, $response->getStatusCode());

        $responseBody = json_decode($response->getBody()->getContents(), true);
        self::assertTrue($responseBody['success']);
        self::assertSame(42, $responseBody['data']['uid']);
    }

    #[Test]
    public function listActionReturns401WhenAuthFails(): void
    {
        $request = $this->createRequestMock('', '');

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn('Missing Authorization header');

        $response = $this->controller->listAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function listActionReturnsWebsitesOnSuccess(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer valid-key');
        $request->method('getQueryParams')->willReturn(['search' => '', 'limit' => '10', 'offset' => '0']);

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn(true);

        $website1 = $this->createMock(Website::class);
        $website1->method('toArray')->willReturn(['uid' => 1, 'url' => 'https://a.tum.de']);

        $website2 = $this->createMock(Website::class);
        $website2->method('toArray')->willReturn(['uid' => 2, 'url' => 'https://b.tum.de']);

        $this->websiteServiceMock
            ->method('findAll')
            ->with('', 10, 0)
            ->willReturn([$website1, $website2]);

        $this->websiteServiceMock
            ->method('countAll')
            ->with('')
            ->willReturn(2);

        $response = $this->controller->listAction($request);

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
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer valid-key');
        $request->method('getQueryParams')->willReturn(['uid' => '999']);

        $this->authServiceMock
            ->method('validateRequest')
            ->willReturn(true);

        $this->websiteServiceMock
            ->method('findByUid')
            ->with(999)
            ->willReturn(null);

        $response = $this->controller->getAction($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
    }

    private function createRequestMock(string $authHeader, string $body): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn($authHeader);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
