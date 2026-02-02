<?php

declare(strict_types=1);

namespace Tum\Webinfo\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Tum\Webinfo\Service\ApiAuthenticationService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class ApiAuthenticationServiceTest extends TestCase
{
    private function createService(?array $config = null): ApiAuthenticationService
    {
        $extensionConfigurationStub = $this->createStub(ExtensionConfiguration::class);
        $extensionConfigurationStub
            ->method('get')
            ->willReturn($config ?? [
                'apiEnabled' => true,
                'apiKey' => 'test-api-key-12345',
                'allowedDomains' => '*.tum.de',
            ]);

        return new ApiAuthenticationService($extensionConfigurationStub);
    }

    private function createRequestStub(string $authHeader): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($authHeader);

        return $request;
    }

    #[Test]
    public function validateRequestReturnsTrueWithValidApiKey(): void
    {
        $service = $this->createService();
        $request = $this->createRequestStub('Bearer test-api-key-12345');

        $result = $service->validateRequest($request);

        self::assertTrue($result);
    }

    #[Test]
    public function validateRequestReturnsErrorWithInvalidApiKey(): void
    {
        $service = $this->createService();
        $request = $this->createRequestStub('Bearer wrong-api-key');

        $result = $service->validateRequest($request);

        self::assertIsString($result);
        self::assertStringContainsString('Invalid API key', $result);
    }

    #[Test]
    public function validateRequestReturnsErrorWithMissingAuthHeader(): void
    {
        $service = $this->createService();
        $request = $this->createRequestStub('');

        $result = $service->validateRequest($request);

        self::assertIsString($result);
        self::assertStringContainsString('Missing Authorization header', $result);
    }

    #[Test]
    #[DataProvider('domainPatternProvider')]
    public function isDomainAllowedMatchesPatterns(string $domain, array $patterns, bool $expected): void
    {
        $service = $this->createService();
        $result = $service->isDomainAllowed($domain, $patterns);
        self::assertSame($expected, $result);
    }

    public static function domainPatternProvider(): array
    {
        return [
            'tum.de wildcard matches subdomain' => [
                'test.tum.de',
                ['*.tum.de'],
                true,
            ],
            'tum.de wildcard matches deep subdomain' => [
                'deep.sub.tum.de',
                ['*.tum.de'],
                true,
            ],
            'exact match' => [
                'www.example.com',
                ['www.example.com'],
                true,
            ],
            'no match for different domain' => [
                'test.example.com',
                ['*.tum.de'],
                false,
            ],
            'multiple patterns with match' => [
                'test.tum.de',
                ['*.example.com', '*.tum.de'],
                true,
            ],
            'multiple patterns without match' => [
                'test.other.com',
                ['*.example.com', '*.tum.de'],
                false,
            ],
            'empty domain' => [
                '',
                ['*.tum.de'],
                false,
            ],
            'empty patterns' => [
                'test.tum.de',
                [],
                false,
            ],
        ];
    }

    #[Test]
    public function validateOriginDomainReturnsTrueForAllowedDomain(): void
    {
        $service = $this->createService();

        $body = json_encode(['domain' => 'test.tum.de', 'url' => 'https://test.tum.de']);
        $request = $this->createRequestStubWithBody('Bearer test-api-key-12345', $body);

        $result = $service->validateOriginDomain($request);

        self::assertTrue($result);
    }

    #[Test]
    public function validateOriginDomainReturnsErrorForDisallowedDomain(): void
    {
        $service = $this->createService();

        $body = json_encode(['domain' => 'test.example.com', 'url' => 'https://test.example.com']);
        $request = $this->createRequestStubWithBody('Bearer test-api-key-12345', $body);

        $result = $service->validateOriginDomain($request);

        self::assertIsString($result);
        self::assertStringContainsString('Domain not allowed', $result);
    }

    private function createRequestStubWithBody(string $authHeader, string $body): ServerRequestInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($authHeader);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
