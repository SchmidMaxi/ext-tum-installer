<?php
declare(strict_types=1);

namespace Tum\Installer\Controller;

use Psr\Http\Message\ResponseInterface;
use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
use Tum\Installer\Domain\Model\WebinfoData;
use Tum\Installer\Service\InstallerService;
use Tum\Installer\Service\WebinfoApiService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class BackendInstallerController extends ActionController
{
    public function __construct(
        private readonly InstallerService $installerService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly WebinfoApiService $webinfoApiService,
        private readonly Typo3Version $typo3Version
    ) {}

    public function indexAction(): ResponseInterface
    {
        // Check if Step 1 was completed - redirect to Step 2
        $step1Completed = $GLOBALS['BE_USER']->getSessionData('installer_step1_completed');

        if ($step1Completed) {
            return $this->redirect('step2');
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer');
        $moduleTemplate->assign('currentStep', 1);
        return $moduleTemplate->renderResponse('BackendInstaller/Index');
    }

    public function step2Action(): ResponseInterface
    {
        // Check if Step 1 was completed
        $step1Completed = $GLOBALS['BE_USER']->getSessionData('installer_step1_completed');
        $step1Config = $GLOBALS['BE_USER']->getSessionData('installer_step1_config');

        if (!$step1Completed || !$step1Config) {
            $this->addFlashMessage(
                'Bitte zuerst Schritt 1 abschließen.',
                'Hinweis',
                ContextualFeedbackSeverity::INFO
            );
            return $this->redirect('index');
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer - Webinfo');
        $moduleTemplate->assign('currentStep', 2);
        $moduleTemplate->assign('step1Config', $step1Config);

        // Get current TYPO3 version for pre-fill
        $majorVersion = $this->typo3Version->getMajorVersion();
        $moduleTemplate->assign('typo3Version', 'v' . $majorVersion);

        return $moduleTemplate->renderResponse('BackendInstaller/Step2');
    }

    public function submitWebinfoAction(
        string $umgebung = '',
        string $organizationUnit = '',
        string $websiteType = '',
        string $typo3Version = '',
        string $laufzeitBis = '',
        string $nachLaufzeitende = '',
        string $notiz = ''
    ): ResponseInterface {
        $step1Config = $GLOBALS['BE_USER']->getSessionData('installer_step1_config');

        if (!$step1Config) {
            $this->addFlashMessage(
                'Session abgelaufen. Bitte Installation wiederholen.',
                'Fehler',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        try {
            $webinfoData = new WebinfoData(
                umgebung: $umgebung,
                organizationUnit: $organizationUnit,
                websiteType: $websiteType,
                typo3Version: $typo3Version,
                laufzeitBis: !empty($laufzeitBis) ? new \DateTime($laufzeitBis) : null,
                nachLaufzeitende: $nachLaufzeitende,
                notiz: $notiz
            );

            $this->webinfoApiService->push($webinfoData, $step1Config);

            // Clear session data
            $GLOBALS['BE_USER']->setSessionData('installer_step1_completed', null);
            $GLOBALS['BE_USER']->setSessionData('installer_step1_config', null);

            $this->addFlashMessage(
                'Webinfo erfolgreich übermittelt!',
                'Erfolg',
                ContextualFeedbackSeverity::OK
            );

        } catch (\Exception $e) {
            $this->addFlashMessage(
                $e->getMessage(),
                'Fehler bei Webinfo-Übermittlung',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->redirect('index');
    }

    public function skipWebinfoAction(): ResponseInterface
    {
        // Clear session and go back to index
        $GLOBALS['BE_USER']->setSessionData('installer_step1_completed', null);
        $GLOBALS['BE_USER']->setSessionData('installer_step1_config', null);

        $this->addFlashMessage(
            'Webinfo-Übermittlung übersprungen.',
            'Hinweis',
            ContextualFeedbackSeverity::INFO
        );

        return $this->redirect('index');
    }

    public function executeAction(
        string $setupName,
        string $navName = '',
        string $domain = '',
        string $wid = '',
        string $parentOu = '',
        // ZURÜCK: department
        string $department = '',

        string $siteNameDe = '',
        string $siteNameEn = '',
        string $parentOuNameDe = '',
        string $parentOuNameEn = '',
        string $parentOuUrlDe = '',
        string $parentOuUrlEn = '',
        string $imprint = '',
        string $accessibility = '',
        string $matomoId = '',

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

            if (!$this->installerService->isSetupAllowed($type)) {
                $this->addFlashMessage('Installation blockiert: Haupt-Installation existiert bereits.', 'Fehler', ContextualFeedbackSeverity::WARNING);
                return $this->redirect('index');
            }

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
                department: $department, // Hier wird der Pfad übergeben (bei Archiv)

                siteNameDe: $siteNameDe ?: $navName,
                siteNameEn: $siteNameEn ?: $navName . ' (EN)',
                parentOuNameDe: $parentOuNameDe,
                parentOuNameEn: $parentOuNameEn,
                parentOuUrlDe: $parentOuUrlDe,
                parentOuUrlEn: $parentOuUrlEn,
                imprint: $imprint,
                accessibility: $accessibility,
                matomoId: $matomoId,

                hasNews: (bool)$news,
                hasIntropage: (bool)$intropage,
                hasCurlContent: (bool)$curlContent,
                hasMemberList: (bool)$memberList,
                hasCourses: (bool)$courses,
                hasVcard: (bool)$vcard
            );

            $this->installerService->install($config);

            $this->addFlashMessage(sprintf('Installation "%s" erfolgreich!', $setupName), 'Erfolg', ContextualFeedbackSeverity::OK);

        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Fehler', ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('index');
    }
}