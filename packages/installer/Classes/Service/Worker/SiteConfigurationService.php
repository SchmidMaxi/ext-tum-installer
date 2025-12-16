<?php
declare(strict_types=1);

namespace Tum\Installer\Service\Worker;

use Symfony\Component\Yaml\Yaml;
use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SiteConfigurationService
{
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function generate(InstallationConfig $config): void
    {
        $navName = strtolower($config->navName);
        $domain = strtolower($config->domain);

        // Base Path Logik
        $basePath = '/' . $navName . '/';

        if ($config->type === SetupType::STANDALONE) {
            $basePath = '/';
        } elseif ($config->type === SetupType::ARCHIV) {
            // Archiv Pfad Logik: /school/dept/kürzel/
            $parts = array_filter([$config->parentOu, $config->department]);
            $prefix = !empty($parts) ? '/' . implode('/', $parts) : '';

            // Das echte Kürzel ist der letzte Teil des zusammengesetzten Namens
            $shortCode = explode('-', $navName);
            $shortCode = end($shortCode);

            $basePath = $prefix . '/' . $shortCode . '/';
        }

        $this->writeConfig($navName, $navName, $domain, $basePath, $config->siteNameDe, $config);

        // Domain Root für Setup1/Standalone
        if ($config->type === SetupType::SETUP1 || $config->type === SetupType::STANDALONE) {
            $this->writeConfig($navName . '-d', 'Domain Root', $domain, '/', 'Domain Root', $config);
        }
    }

    private function writeConfig(string $identifier, string $rootPageTitle, string $domain, string $basePath, string $siteTitle, InstallationConfig $config): void
    {
        $rootPageId = $this->findRootPageId($rootPageTitle);
        // Fallback für Domain Root
        if ($rootPageId === 0 && $rootPageTitle === 'Domain Root') {
            $rootPageId = $this->findRootPageId('sRoot');
        }
        if ($rootPageId === 0) return;

        $configPath = Environment::getConfigPath() . '/sites/' . $identifier;
        if (!is_dir($configPath)) GeneralUtility::mkdir_deep($configPath);

        // 1. config.yaml
        $imports = [['resource' => 'EXT:sitetum/Configuration/Sites/base.yaml']];
        if ($config->hasNews) {
            $imports[] = ['resource' => 'EXT:sitetum/Configuration/Sites/ext-news.yaml'];
        }

        $configData = [
            'base' => 'https://' . $domain . $basePath,
            'rootPageId' => $rootPageId,
            'websiteTitle' => $siteTitle,
            'sitePackage' => 'sitetum',
            'imports' => $imports,
            'languages' => [
                ['title' => 'Deutsch', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'typo3Language' => 'de', 'locale' => 'de_DE.UTF-8', 'iso-639-1' => 'de', 'navigationTitle' => 'Deutsch', 'flag' => 'de'],
                ['title' => 'English', 'enabled' => true, 'languageId' => 1, 'base' => '/en/', 'typo3Language' => 'en', 'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en', 'navigationTitle' => 'English', 'fallbackType' => 'strict', 'fallbacks' => '0', 'flag' => 'en-us-gb'],
            ],
            'errorHandling' => [
                ['errorCode' => 404, 'errorHandler' => 'Page', 'errorContentSource' => 't3://page?uid=' . $rootPageId],
                ['errorCode' => 403, 'errorHandler' => 'Page', 'errorContentSource' => 't3://page?uid=' . $rootPageId]
            ],
            'baseVariants' => [
                ['base' => 'https://lu26xap-v12.typo3.lrz.de' . $basePath, 'condition' => 'applicationContext == "Production/Testing"']
            ]
        ];
        file_put_contents($configPath . '/config.yaml', Yaml::dump($configData, 99, 2));

        // 2. csp.yaml
        $cspData = ['inheritDefault' => true, 'imports' => [['resource' => 'EXT:sitetum/Configuration/Sites/csp.yaml']]];
        file_put_contents($configPath . '/csp.yaml', Yaml::dump($cspData, 99, 2));

        // 3. settings.yaml
        $settingsData = [
            'tum' => [
                'THIS_OU_NAME' => ['de' => $config->siteNameDe, 'en' => $config->siteNameEn],
                'pages' => ['introNaviPid' => 0]
            ],
            'imports' => [['resource' => 'EXT:sitetum/Configuration/Sites/settings.yaml']]
        ];

        if (!empty($config->parentOu)) {
            $settingsData['tum']['PARENT_OU'] = $config->parentOu;
        }

        if ($config->hasNews) {
            $singlePid = $this->findPageId('Detail-' . $config->navName, $rootPageId);
            if ($singlePid === 0) $singlePid = $this->findPageId('Detail', $rootPageId);
            $startingPoint = $this->findPageId('news-system-' . $config->navName);

            $settingsData['tum_news'] = ['singlePid' => $singlePid, 'startingpoint' => $startingPoint];
        }
        file_put_contents($configPath . '/settings.yaml', Yaml::dump($settingsData, 99, 2));
    }

    private function findRootPageId(string $title): int {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        return (int)$qb->select('uid')->from('pages')->where($qb->expr()->eq('is_siteroot', 1), $qb->expr()->eq('title', $qb->createNamedParameter($title)), $qb->expr()->eq('deleted', 0))->setMaxResults(1)->executeQuery()->fetchOne();
    }

    private function findPageId(string $title, int $pid = -1): int {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->select('uid')->from('pages')->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))->andWhere($qb->expr()->eq('deleted', 0))->setMaxResults(1);
        if ($pid > 0) $qb->andWhere($qb->expr()->eq('pid', $pid));
        return (int)$qb->executeQuery()->fetchOne();
    }
}