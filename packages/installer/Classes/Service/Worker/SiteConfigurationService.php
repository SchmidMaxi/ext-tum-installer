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
        // Hinweis: Wenn $navName "t3" ist, wird das hier zu "/t3/".
        // Das ist korrekt so, da es dynamisch aus dem Input kommt.
        $basePath = '/' . $navName . '/';

        if ($config->type === SetupType::STANDALONE) {
            $basePath = '/';
        } elseif ($config->type === SetupType::ARCHIV) {
            // Archiv Pfad Logik: /school/dept/kürzel/
            $parts = array_filter([$config->parentOu, $config->department]);
            $prefix = !empty($parts) ? '/' . implode('/', $parts) : '';

            $shortCode = explode('-', $navName);
            $shortCode = end($shortCode);

            $basePath = $prefix . '/' . $shortCode . '/';
        }

        $this->writeConfig($navName, $navName, $domain, $basePath, $config->siteNameDe, $config);

        // Domain Root für Setup1/Standalone
        // Hier wird die Config für die Root-Seite ("Domain Root") erstellt
        if ($config->type === SetupType::SETUP1 || $config->type === SetupType::STANDALONE) {
            $this->writeConfig($navName . '-d', 'Domain Root', $domain, '/', 'Domain Root', $config);
        }
    }

    private function writeConfig(string $identifier, string $rootPageTitle, string $domain, string $basePath, string $siteTitle, InstallationConfig $config): void
    {
        // Robusteres Finden der Root Page ID
        $rootPageId = $this->findRootPageId($rootPageTitle);

        if ($rootPageId === 0) {
            // Logging oder silent fail - ohne Root Page keine Site Config
            return;
        }

        $configPath = Environment::getConfigPath() . '/sites/' . $identifier;
        if (!is_dir($configPath)) GeneralUtility::mkdir_deep($configPath);

        // 1. config.yaml
        $imports = [['resource' => 'EXT:sitetum/Configuration/Sites/base.yaml']];
        if ($config->hasNews) {
            $imports[] = ['resource' => 'EXT:sitetum/Configuration/Sites/ext-news.yaml'];
        }

        // KÜRZEL für die URL-Generierung (navName)
        $urlKuerzel = strtolower($config->navName);

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
            // HIER: Dynamische URL mit Kürzel statt hardcoded "lu26xap"
            'baseVariants' => [
                [
                    'base' => 'https://' . $urlKuerzel . '-v12.typo3.lrz.de' . $basePath,
                    'condition' => 'applicationContext == "Production/Testing"'
                ]
            ]
        ];
        file_put_contents($configPath . '/config.yaml', Yaml::dump($configData, 99, 2));

        // 2. csp.yaml (Unverändert)
        $cspData = ['inheritDefault' => true, 'imports' => [['resource' => 'EXT:sitetum/Configuration/Sites/csp.yaml']]];
        file_put_contents($configPath . '/csp.yaml', Yaml::dump($cspData, 99, 2));

        // 3. settings.yaml (Unverändert)
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

        // 1. Versuch: Suche exakt nach Titel und is_siteroot
        $uid = (int)$qb->select('uid')->from('pages')
            ->where(
                $qb->expr()->eq('is_siteroot', 1),
                $qb->expr()->eq('title', $qb->createNamedParameter($title)),
                $qb->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)->executeQuery()->fetchOne();

        if ($uid > 0) {
            return $uid;
        }

        // 2. Fallback: Falls wir "Domain Root" suchen, es aber nicht exakt so heißt (Import-Problem?),
        // nehmen wir die erste Seite auf Root-Ebene (PID 0), die eine Site Root ist.
        if ($title === 'Domain Root' || $title === 'sRoot') {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            return (int)$qb->select('uid')->from('pages')
                ->where(
                    $qb->expr()->eq('pid', 0),
                    $qb->expr()->eq('is_siteroot', 1),
                    $qb->expr()->eq('deleted', 0)
                )
                ->orderBy('uid', 'ASC') // Die "älteste" Root Seite gewinnen lassen
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        }

        return 0;
    }

    private function findPageId(string $title, int $pid = -1): int {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->select('uid')->from('pages')
            ->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))
            ->andWhere($qb->expr()->eq('deleted', 0))
            ->setMaxResults(1);
        if ($pid > 0) $qb->andWhere($qb->expr()->eq('pid', $pid));
        return (int)$qb->executeQuery()->fetchOne();
    }
}