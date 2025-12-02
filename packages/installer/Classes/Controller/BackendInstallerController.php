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

    /**
     * Zeigt das Formular an
     */
    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer');

        // Hier könntest du Setups dynamisch aus dem Ordner lesen,
        // der Einfachheit halber hardcoden wir sie erstmal oder geben leeres Array.
        $this->view->assign('setups', ['Setup1', 'Setup3']);

        $moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Führt das Setup aus
     * Die Argumente heißen genau wie die 'name'-Attribute in deinem HTML Formular
     */
    public function executeAction(string $setupName, string $navName, string $domain, string $wid = '', string $lrzid = ''): ResponseInterface
    {
        // Config Array bauen
        $config = [
            'navName' => $navName,
            'domain' => $domain,
            'wid' => $wid,
            'lrzid' => $lrzid
        ];

        try {
            // 1. Setup Service rufen (Macht DB Import + Site Config)
            $this->setupService->runSetup($setupName, $config);
            $this->setupService->createSiteConfiguration($config);

            $this->addFlashMessage(
                sprintf('Installation für "%s" (%s) erfolgreich abgeschlossen!', $navName, $domain),
                'Erfolg',
                AbstractMessage::OK
            );
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                $e->getMessage(),
                'Fehler bei Installation',
                AbstractMessage::ERROR
            );
        }

        return $this->redirect('index');
    }
}