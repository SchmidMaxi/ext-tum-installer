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
    private function createService(
        ?RequestFactory $requestFactory = null,
        ?ExtensionConfiguration $extensionConfiguration = null
    ): WebinfoApiService {
        return new WebinfoApiService(
            $requestFactory ?? $this->createStub(RequestFactory::class),
            $extensionConfiguration ?? $this->createStub(ExtensionConfiguration::class)
        );
    }

    #[Test]
    public function isDomainAllowedReturnsTrueForTumDeDomain(): void
    {
        $service = $this->createService();

        self::assertTrue($service->isDomainAllowed('test.tum.de'));
        self::assertTrue($service->isDomainAllowed('www.test.tum.de'));
        self::assertTrue($service->isDomainAllowed('sub.domain.tum.de'));
        self::assertTrue($service->isDomainAllowed('tum.de'));
    }

    #[Test]
    public function isDomainAllowedReturnsFalseForNonTumDomain(): void
    {
        $service = $this->createService();

        self::assertFalse($service->isDomainAllowed('test.example.com'));
        self::assertFalse($service->isDomainAllowed('tum.de.fake.com'));
        self::assertFalse($service->isDomainAllowed('not-tum.de'));
        self::assertFalse($service->isDomainAllowed(''));
    }

    #[Test]
    public function isDomainAllowedReturnsFalseForEmptyDomain(): void
    {
        $service = $this->createService();

        self::assertFalse($service->isDomainAllowed(''));
    }

    #[Test]
    public function pushThrowsExceptionForNonTumDomain(): void
    {
        $extensionConfigurationStub = $this->createStub(ExtensionConfiguration::class);
        $extensionConfigurationStub
            ->method('get')
            ->willReturn([
                'webinfoApiUrl' => 'https://webinfo.tum.de/api',
                'webinfoApiKey' => 'test-key',
                'webinfoApiEnabled' => true,
            ]);

        $service = $this->createService(extensionConfiguration: $extensionConfigurationStub);

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

        $service->push($webinfoData, $installationConfig);
    }

    #[Test]
    public function pushThrowsExceptionWhenApiUrlNotConfigured(): void
    {
        $extensionConfigurationStub = $this->createStub(ExtensionConfiguration::class);
        $extensionConfigurationStub
            ->method('get')
            ->willReturn([
                'webinfoApiUrl' => '',
                'webinfoApiKey' => 'test-key',
                'webinfoApiEnabled' => true,
            ]);

        $service = $this->createService(extensionConfiguration: $extensionConfigurationStub);

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

        $service->push($webinfoData, $installationConfig);
    }

    #[Test]
    public function pushSkipsSilentlyWhenApiDisabled(): void
    {
        $extensionConfigurationStub = $this->createStub(ExtensionConfiguration::class);
        $extensionConfigurationStub
            ->method('get')
            ->willReturn([
                'webinfoApiUrl' => 'https://webinfo.tum.de/api',
                'webinfoApiKey' => 'test-key',
                'webinfoApiEnabled' => false,
            ]);

        // Request factory should never be called - use mock for this expectation
        $requestFactoryMock = $this->createMock(RequestFactory::class);
        $requestFactoryMock
            ->expects(self::never())
            ->method('request');

        $service = $this->createService(
            requestFactory: $requestFactoryMock,
            extensionConfiguration: $extensionConfigurationStub
        );

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
        $service->push($webinfoData, $installationConfig);
        self::assertTrue(true); // If we get here, the test passed
    }
}
