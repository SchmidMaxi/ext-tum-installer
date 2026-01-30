<?php

declare(strict_types=1);

namespace Tum\Installer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
use Tum\Installer\Service\InstallerService;
use Tum\Installer\Service\ProgressTrackingService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Http\JsonResponse;

class AjaxController
{
    public function __construct(
        private readonly InstallerService $installerService,
        private readonly ProgressTrackingService $progressService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly Random $random
    ) {}

    public function progressAction(ServerRequestInterface $request): ResponseInterface
    {
        $installationId = $request->getQueryParams()['installationId'] ?? '';

        if (empty($installationId)) {
            return new JsonResponse(['error' => 'Missing installationId'], 400);
        }

        $progress = $this->progressService->getProgress($installationId);

        if ($progress === null) {
            return new JsonResponse(['error' => 'Installation not found'], 404);
        }

        return new JsonResponse($progress);
    }

    public function executeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();

        // Generate unique installation ID
        $installationId = $this->random->generateRandomHexString(16);

        // Initialize progress tracking
        $this->progressService->initProgress($installationId);

        try {
            // Parse form data
            $setupName = $body['setupName'] ?? '';
            $type = SetupType::tryFrom($setupName);

            if (!$type) {
                throw new \InvalidArgumentException("Ungültiger Setup Typ: $setupName");
            }

            // Check if setup is allowed
            $this->progressService->updateStep($installationId, 0, 'in_progress');

            if (!$this->installerService->isSetupAllowed($type)) {
                throw new \RuntimeException('Installation blockiert: Haupt-Installation existiert bereits.');
            }

            // Get archiv domain from extension config if needed
            $domain = $body['domain'] ?? '';
            if ($type === SetupType::ARCHIV) {
                try {
                    $extConf = $this->extensionConfiguration->get('installer');
                    $domain = $extConf['archivDomain'] ?? $domain;
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            // Create InstallationConfig
            $config = new InstallationConfig(
                type: $type,
                navName: $body['navName'] ?? '',
                domain: $domain,
                wid: $body['wid'] ?? '',
                parentOu: $body['parentOu'] ?? '',
                department: $body['department'] ?? '',
                siteNameDe: $body['siteNameDe'] ?: ($body['navName'] ?? ''),
                siteNameEn: $body['siteNameEn'] ?: (($body['navName'] ?? '') . ' (EN)'),
                parentOuNameDe: $body['parentOuNameDe'] ?? '',
                parentOuNameEn: $body['parentOuNameEn'] ?? '',
                parentOuUrlDe: $body['parentOuUrlDe'] ?? '',
                parentOuUrlEn: $body['parentOuUrlEn'] ?? '',
                imprint: $body['imprint'] ?? '',
                accessibility: $body['accessibility'] ?? '',
                matomoId: $body['matomoId'] ?? '',
                hasNews: (bool)($body['news'] ?? 0),
                hasIntropage: (bool)($body['intropage'] ?? 0),
                hasCurlContent: (bool)($body['curlContent'] ?? 0),
                hasMemberList: (bool)($body['memberList'] ?? 0),
                hasCourses: (bool)($body['courses'] ?? 0),
                hasVcard: (bool)($body['vcard'] ?? 0)
            );

            // Create progress callback
            $progressCallback = function (int $step, string $stepKey) use ($installationId) {
                $this->progressService->updateStep($installationId, $step, 'in_progress');
            };

            // Run installation with progress tracking
            $this->installerService->install($config, $progressCallback);

            // Mark as complete
            $this->progressService->complete($installationId);

            // Store config in session for Step 2
            $GLOBALS['BE_USER']->setSessionData('installer_step1_config', $config->toArray());
            $GLOBALS['BE_USER']->setSessionData('installer_step1_completed', true);

            return new JsonResponse([
                'success' => true,
                'installationId' => $installationId,
                'message' => 'Installation erfolgreich!'
            ]);

        } catch (\Exception $e) {
            $this->progressService->setError($installationId, $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'installationId' => $installationId,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
