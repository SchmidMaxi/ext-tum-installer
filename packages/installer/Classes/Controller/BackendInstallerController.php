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
     * Schritt 1: Das Auswahl-Formular anzeigen
     */
    public function indexAction(): ResponseInterface
    {
        // Erstellt den Rahmen des TYPO3 Backends (Header, DocHeader etc.)
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Installer');

        // Wir geben mögliche Setups an die View
        $this->view->assign('setups', ['Setup1', 'Setup3']); // Könnte man auch dynamisch aus dem Ordner lesen

        $moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Schritt 2: Das Setup ausführen (wenn man auf "Start" klickt)
     */
    public function executeAction(string $setupName): ResponseInterface
    {
        try {
            // Die eigentliche Arbeit macht unser Service
            $this->setupService->runSetup($setupName);

            $this->addFlashMessage(
                sprintf('Das Setup "%s" wurde erfolgreich installiert.', $setupName),
                'Erfolg',
                AbstractMessage::OK
            );
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                $e->getMessage(),
                'Fehler beim Setup',
                AbstractMessage::ERROR
            );
        }

        // Redirect zurück zum Formular
        return $this->redirect('index');
    }
}