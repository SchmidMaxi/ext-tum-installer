<?php
declare(strict_types=1);

namespace Tum\Installer\Controller;

use Psr\Http\Message\ResponseInterface;
use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
use Tum\Installer\Service\InstallerService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class BackendInstallerController extends ActionController
{
    public function __construct(
        private readonly InstallerService $installerService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer');
        return $moduleTemplate->renderResponse('BackendInstaller/Index');
    }

    public function executeAction(
        string $setupName,
        string $navName = '',
        string $domain = '',
        string $wid = '',
        // Text Fields
        string $parentOu = '',
        string $department = '',
        string $siteNameDe = '',
        string $siteNameEn = '',
        string $parentOuNameDe = '',
        string $parentOuNameEn = '',
        string $parentOuUrlDe = '',
        string $parentOuUrlEn = '',
        string $imprint = '',
        string $accessibility = '',
        // Checkboxes (int 0/1 vom Fluid Form)
        int $news = 0,
        int $intropage = 0,
        int $curlContent = 0,
        int $memberList = 0,
        int $courses = 0,
        int $vcard = 0
    ): ResponseInterface
    {
        try {
            $type = SetupType::tryFrom($setupName);
            if (!$type) throw new \InvalidArgumentException("Ungültiger Setup Typ: $setupName");

            // Archiv Override (Optional)
            if ($type === SetupType::ARCHIV) {
                try {
                    $extConf = $this->extensionConfiguration->get('installer');
                    $domain = $extConf['archivDomain'] ?? $domain;
                } catch (\Exception $e) {}
            }

            $config = new InstallationConfig(
                type: $type,
                navName: $navName,
                domain: $domain,
                wid: $wid,
                parentOu: $parentOu,
                department: $department,
                siteNameDe: $siteNameDe ?: $navName,
                siteNameEn: $siteNameEn ?: $navName . ' (EN)',
                parentOuNameDe: $parentOuNameDe,
                parentOuNameEn: $parentOuNameEn,
                parentOuUrlDe: $parentOuUrlDe,
                parentOuUrlEn: $parentOuUrlEn,
                imprint: $imprint,
                accessibility: $accessibility,
                hasNews: (bool)$news,
                hasIntropage: (bool)$intropage,
                hasCurlContent: (bool)$curlContent,
                hasMemberList: (bool)$memberList,
                hasCourses: (bool)$courses,
                hasVcard: (bool)$vcard
            );

            $this->installerService->install($config);

            $this->addFlashMessage(
                sprintf('Installation "%s" erfolgreich durchgeführt!', $setupName),
                'Erfolg',
                ContextualFeedbackSeverity::OK
            );

        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Fehler', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('index');
    }
}