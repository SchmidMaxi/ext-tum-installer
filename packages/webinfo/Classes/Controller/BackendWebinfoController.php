<?php

declare(strict_types=1);

namespace Tum\Webinfo\Controller;

use Psr\Http\Message\ResponseInterface;
use Tum\Webinfo\Service\WebsiteService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class BackendWebinfoController extends ActionController
{
    public function __construct(
        private readonly WebsiteService $websiteService,
        private readonly ModuleTemplateFactory $moduleTemplateFactory
    ) {}

    public function indexAction(string $search = '', int $page = 1): ResponseInterface
    {
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $websites = $this->websiteService->findAll($search, $limit, $offset);
        $total = $this->websiteService->countAll($search);
        $totalPages = (int)ceil($total / $limit);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Webinfo');

        $moduleTemplate->assignMultiple([
            'websites' => $websites,
            'search' => $search,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'limit' => $limit,
        ]);

        return $moduleTemplate->renderResponse('BackendWebinfo/Index');
    }

    public function detailAction(int $uid): ResponseInterface
    {
        $website = $this->websiteService->findByUid($uid);

        if ($website === null) {
            $this->addFlashMessage('Website nicht gefunden.', 'Fehler', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('TUM Webinfo - Details');
        $moduleTemplate->assign('website', $website);

        return $moduleTemplate->renderResponse('BackendWebinfo/Detail');
    }
}
