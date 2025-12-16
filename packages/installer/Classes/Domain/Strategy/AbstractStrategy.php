<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use TYPO3\CMS\Core\Database\ConnectionPool;

abstract class AbstractStrategy implements SetupStrategyInterface
{
    public function __construct(protected readonly ConnectionPool $connectionPool) {}

    /**
     * Standard Upload Pfad Logik: 1:wid/kürzel/_my_direct_uploads/
     */
    protected function getStandardUploadPath(InstallationConfig $config): string
    {
        return "1:{$config->wid}/{$config->navName}/_my_direct_uploads/";
    }

    protected function findPageId(string $title, int $pid = -1): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->select('uid')->from('pages')->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))->andWhere($qb->expr()->eq('deleted', 0))->setMaxResults(1);
        if ($pid !== -1) $qb->andWhere($qb->expr()->eq('pid', $pid));
        return (int)$qb->executeQuery()->fetchOne();
    }

    protected function updateTsConfig(int $pageId, string $tsConfig): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['tsconfig' => $tsConfig], ['uid' => $pageId]);
    }

    /**
     * Generiert den Standard TSConfig Block (Upload + News Preview)
     */
    protected function generateStandardTsConfig(InstallationConfig $config, int $rootPageId): string
    {
        $tsConfig = "options.defaultUploadFolder = {$config->uploadPath}\n";

        if ($config->hasNews) {
            // News Page ID finden
            $newsPageId = $this->findPageId('Aktuelles-' . $config->navName);
            if ($newsPageId === 0) $newsPageId = $this->findPageId('Aktuelles', $rootPageId);

            if ($newsPageId > 0) {
                $tsConfig .= "\nTCEMAIN.preview.tx_news_domain_model_news.previewPageId = {$newsPageId}\n";
                $tsConfig .= "TCEMAIN.preview.tx_news_domain_model_news.useDefaultLanguageRecord = 0\n";
                $tsConfig .= "TCEMAIN.preview.tx_news_domain_model_news.fieldToParameterMap.uid = tx_news_pi1[news_preview]\n";
                $tsConfig .= "TCEMAIN.preview.tx_news_domain_model_news.additionalGetParameters.tx_news_pi1.controller = News\n";
                $tsConfig .= "TCEMAIN.preview.tx_news_domain_model_news.additionalGetParameters.tx_news_pi1.action = detail\n";
            }
        }
        return $tsConfig;
    }
}