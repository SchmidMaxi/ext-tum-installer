<?php

declare(strict_types=1);

namespace Tum\Installer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tum\Installer\Domain\Model\WebinfoData;
use Tum\Installer\Service\WebinfoApiService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class WebinfoApiServiceTest extends TestCase
{
    private WebinfoApiService $service;
    private RequestFactory $requestFactoryMock;
    private ExtensionConfiguration $extensionConfigurationMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactoryMock = $this->createMock(RequestFactory::class);
        $this->extensionConfigurationMock = $this->createMock(ExtensionConfiguration::class);

        $this->service = new WebinfoApiService(
            $this->requestFactoryMock,
            $this->extensionConfigurationMock
        );
    }

    #[Test]
    public function isDomainAllowedReturnsTrueForTumDeDomain(): void
    {
        self::assertTrue($this->service->isDomainAllowed('test.tum.de'));
        self::assertTrue($this->service->isDomainAllowed('www.test.tum.de'));
        self::assertTrue($this->service->isDomainAllowed('sub.domain.tum.de'));
        self::assertTrue($this->service->isDomainAllowed('tum.de'));
    }

    #[Test]
    public function isDomainAllowedReturnsFalseForNonTumDomain(): void
    {
        self::assertFalse($this->service->isDomainAllowed('test.example.com'));
        self::assertFalse($this->service->isDomainAllowed('tum.de.fake.com'));
        self::assertFalse($this->service->isDomainAllowed('not-tum.de'));
        self::assertFalse($this->service->isDomainAllowed(''));
    }

    #[Test]
    public function isDomainAllowedReturnsFalseForEmptyDomain(): void
    {
        self::assertFalse($this->service->isDomainAllowed(''));
    }

    #[Test]
    public function pushThrowsExceptionForNonTumDomain(): void
    {
        $this->extensionConfigurationMock
            ->method('get')
            ->with('installer')
            ->willReturn([
                'webinfoApiUrl' => 'https://webinfo.tum.de/api',
                'webinfoApiKey' => 'test-key',
                'webinfoApiEnabled' => true,
            ]);

        $webinfoData = new WebinfoData(
            umgebung: 'www-v23',
            organizationUnit: 'Test OU',
            websiteType: 'Einrichtung',
            typo3Version: 'v14',
            laufzeitBis: null,
            nachLaufzeitende: 'Archiv',
            notiz: ''
        );

        $installationConfig = [
            'domain' => 'example.com',
            'navName' => 'test',
            'wid' => 'w00test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API-Push nur für *.tum.de Domains erlaubt');

        $this->service->push($webinfoData, $installationConfig);
    }

    #[Test]
    public function pushThrowsExceptionWhenApiUrlNotConfigured(): void
    {
        $this->extensionConfigurationMock
            ->method('get')
            ->with('installer')
            ->willReturn([
                'webinfoApiUrl' => '',
                'webinfoApiKey' => 'test-key',
                'webinfoApiEnabled' => true,
            ]);

        $webinfoData = new WebinfoData(
            umgebung: 'www-v23',
            organizationUnit: 'Test OU',
            websiteType: 'Einrichtung',
            typo3Version: 'v14',
            laufzeitBis: null,
            nachLaufzeitende: 'Archiv',
            notiz: ''
        );

        $installationConfig = [
            'domain' => 'test.tum.de',
            'navName' => 'test',
            'wid' => 'w00test',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webinfo API URL nicht konfiguriert');

        $this->service->push($webinfoData, $installationConfig);
    }

    #[Test]
    public function pushSkipsSilentlyWhenApiDisabled(): void
    {
        $this->extensionConfigurationMock
            ->method('get')
            ->with('installer')
            ->willReturn([
                'webinfoApiUrl' => 'https://webinfo.tum.de/api',
                'webinfoApiKey' => 'test-key',
                'webinfoApiEnabled' => false,
            ]);

        // Request factory should never be called
        $this->requestFactoryMock
            ->expects(self::never())
            ->method('request');

        $webinfoData = new WebinfoData(
            umgebung: 'www-v23',
            organizationUnit: 'Test OU',
            websiteType: 'Einrichtung',
            typo3Version: 'v14',
            laufzeitBis: null,
            nachLaufzeitende: 'Archiv',
            notiz: ''
        );

        $installationConfig = [
            'domain' => 'test.tum.de',
            'navName' => 'test',
            'wid' => 'w00test',
        ];

        // Should not throw, just silently return
        $this->service->push($webinfoData, $installationConfig);
        self::assertTrue(true); // If we get here, the test passed
    }
}
