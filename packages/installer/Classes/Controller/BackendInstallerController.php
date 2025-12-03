<?php
declare(strict_types=1);

namespace Tum\Installer\Controller;

use Psr\Http\Message\ResponseInterface;
use Tum\Installer\Service\SetupService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class BackendInstallerController extends ActionController
{
    public function __construct(
        private readonly SetupService $setupService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer');

        $moduleTemplate->assign('setups', ['Setup1', 'Setup3']);

        // Dummy Daten für Parent OU Selectbox (Da wir keine Quelle haben)
        $moduleTemplate->assign('parentOus', [
            '' => 'Keine',
            '1' => 'Zentrale (1)',
            '2' => 'Fakultät Informatik (2)',
            // Hier müssten echte Werte hin oder aus DB geladen werden
        ]);

        return $moduleTemplate->renderResponse('BackendInstaller/Index');
    }

    public function executeAction(
        string $setupName,
        string $navName,
        string $domain,
        string $siteNameDe = '',
        string $siteNameEn = '',
        string $wid = '',
        string $lrzid = '',
        string $parentOu = '',
        string $parentOuNameDe = '',
        string $parentOuNameEn = '',
        string $parentOuUrlDe = '',
        string $parentOuUrlEn = '',
        string $imprint = '',
        string $accessibility = '',
        int $news = 0,
        int $intropage = 0,
        int $curlContent = 0,
        int $memberList = 0,
        int $courses = 0,
        int $vcard = 0
    ): ResponseInterface
    {
        $config = [
            'navName' => $navName,
            'domain' => $domain,
            'siteNameDe' => $siteNameDe ?: $navName,
            'siteNameEn' => $siteNameEn ?: $navName . ' (EN)',
            'wid' => $wid,
            'lrzid' => $lrzid,
            'parentOu' => $parentOu,
            'parentOuNameDe' => $parentOuNameDe,
            'parentOuNameEn' => $parentOuNameEn,
            'parentOuUrlDe' => $parentOuUrlDe,
            'parentOuUrlEn' => $parentOuUrlEn,
            'imprint' => $imprint,
            'accessibility' => $accessibility,
            'news' => (bool)$news,
            'intropage' => (bool)$intropage,
            'curlContent' => (bool)$curlContent,
            'memberList' => (bool)$memberList,
            'courses' => (bool)$courses,
            'vcard' => (bool)$vcard,
        ];

        try {
            $this->setupService->runSetup($setupName, $config);
            $this->setupService->createSiteConfiguration($config, $setupName);

            $this->addFlashMessage(sprintf('Installation für "%s" erfolgreich!', $navName), 'Erfolg', AbstractMessage::OK);
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Fehler', AbstractMessage::ERROR);
        }

        return $this->redirect('index');
    }
}