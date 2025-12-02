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
        // 1. Module Template erstellen (Standard Rahmen)
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer');

        // 2. Variablen an die View übergeben
        // HINWEIS: In v13/14 weisen wir Variablen direkt dem ModuleTemplate zu,
        // wenn wir renderResponse nutzen.
        $moduleTemplate->assign('setups', ['Setup1', 'Setup3']);

        // 3. View rendern (Sucht automatisch nach Templates/BackendInstaller/Index.html)
        return $moduleTemplate->renderResponse('BackendInstaller/Index');
    }

    public function executeAction(string $setupName, string $navName, string $domain, string $wid = '', string $lrzid = '', int $news = 0): ResponseInterface
    {
        $config = [
            'navName' => $navName,
            'domain' => $domain,
            'wid' => $wid,
            'lrzid' => $lrzid,
            'news' => (bool)$news // Checkbox kommt oft als int 0/1
        ];

        try {
            // Hier greift jetzt unser neuer Schutzmechanismus!
            $this->setupService->runSetup($setupName, $config);
            $this->setupService->createSiteConfiguration($config);

            $this->addFlashMessage(
                sprintf('Installation für "%s" erfolgreich!', $navName),
                'Erfolg',
                AbstractMessage::OK
            );
        } catch (\Exception $e) {
            // Wenn der Service "ABBRUCH" wirft, landen wir hier
            $this->addFlashMessage(
                $e->getMessage(),
                'Fehler',
                AbstractMessage::ERROR
            );
        }

        return $this->redirect('index');
    }
}