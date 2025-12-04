<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class SetupService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private YamlService $yamlService,
        private DataProcessingService $dataProcessor,
        private PasswordHashFactory $passwordHashFactory,
        private StorageRepository $storageRepository
    ) {}

    public function runSetup(string $setupName, array $config, ?SymfonyStyle $io = null): void
    {
        // 1. Sicherheits-Check (Verhindert doppeltes Setup 1)
        $this->checkPrerequisites($setupName, $config, $io);

        // 2. Ordnerstruktur anlegen (Auch bei Setup 3 sicherstellen)
        if ($setupName === 'Setup1' || $setupName === 'Setup3') {
            $this->createFolderStructure($config, $io);
        }

        // 3. Variablen für YAML vorbereiten
        if (!empty($config['intropage'])) {
            $config['introTsConfig'] = 'EXT:sitetum/Configuration/PageTs/BackendLayouts/Pagetree/c_intro1col.tsconfig';
        } else {
            $config['introTsConfig'] = '';
        }

        // 4. Import & Verarbeitung
        $fileName = 'Configuration/Installer/' . $setupName . '.yaml';
        $io?->text("--> Lade YAML Datei: $fileName");

        $setupData = $this->yamlService->parseFile($fileName);
        $this->processData($setupData, $config, $io);
    }

    /**
     * Prüft, ob Setup 1 ausgeführt werden darf (sRoot Existenz).
     */
    private function checkPrerequisites(string $setupName, array $config, ?SymfonyStyle $io): void
    {
        if ($setupName !== 'Setup1') {
            return;
        }

        $navName = $config['navName'] ?? '';
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');

        // Check A: Projekt existiert schon?
        if (!empty($navName)) {
            $exists = (bool)$qb->select('uid')->from('pages')
                ->where(
                    $qb->expr()->eq('title', $qb->createNamedParameter(strtolower($navName))),
                    $qb->expr()->eq('deleted', 0)
                )
                ->setMaxResults(1)->executeQuery()->fetchOne();

            if ($exists) {
                throw new \RuntimeException(sprintf("ABBRUCH: Projekt '%s' existiert bereits! Bitte nutze Setup 3 für Updates.", $navName));
            }
        }

        // Check B: sRoot existiert schon?
        $sRootExists = (bool)$qb->select('uid')->from('pages')
            ->where(
                $qb->expr()->eq('title', $qb->createNamedParameter('sRoot')),
                $qb->expr()->eq('pid', 0),
                $qb->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)->executeQuery()->fetchOne();

        if ($sRootExists) {
            throw new \RuntimeException("ABBRUCH: 'sRoot' existiert bereits. Setup 1 darf nur initial ausgeführt werden.");
        }
    }

    public function createSiteConfiguration(array $config, string $setupName, ?SymfonyStyle $io = null): void
    {
        $navName = strtolower($config['navName'] ?? '');
        $domain = strtolower($config['domain'] ?? '');

        if (empty($navName) || empty($domain)) return;

        // Namen (Default: navName)
        $siteNameDe = $config['siteNameDe'] ?? $navName;
        $siteNameEn = $config['siteNameEn'] ?? $navName . ' (EN)';

        // 1. Projekt Site Config
        $this->generateSiteConfigSet($navName, $navName, $domain, '/' . $navName . '/', $siteNameDe, $io, $config);

        // 2. Domain Site Config (nur Setup 1)
        if ($setupName === 'Setup1') {
            $this->generateSiteConfigSet($navName . '-d', $domain, $domain, '/', 'Domain Root', $io, $config);
        }
    }

    private function generateSiteConfigSet(
        string $identifier,
        string $rootPageTitle,
        string $domain,
        string $basePath,
        string $websiteTitle,
        ?SymfonyStyle $io,
        array $config
    ): void {
        $rootPageId = $this->findRootPageId($rootPageTitle);
        if ($rootPageId === 0) {
            $io?->warning("Konnte Root Page '$rootPageTitle' nicht finden. Überspringe Config.");
            return;
        }

        $configPath = Environment::getConfigPath() . '/sites/' . $identifier;
        if (!is_dir($configPath)) GeneralUtility::mkdir_deep($configPath);

        // config.yaml
        $imports = [['resource' => 'EXT:sitetum/Configuration/Sites/base.yaml']];
        if (!empty($config['news'])) {
            $imports[] = ['resource' => 'EXT:sitetum/Configuration/Sites/ext-news.yaml'];
        }

        $configData = [
            'base' => 'https://' . $domain . $basePath,
            'rootPageId' => $rootPageId,
            'websiteTitle' => $websiteTitle,
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

        // csp.yaml
        $cspData = ['inheritDefault' => true, 'imports' => [['resource' => 'EXT:sitetum/Configuration/Sites/csp.yaml']]];
        file_put_contents($configPath . '/csp.yaml', Yaml::dump($cspData, 99, 2));

        // settings.yaml
        $siteNameDe = $config['siteNameDe'] ?? $websiteTitle;
        $siteNameEn = $config['siteNameEn'] ?? $websiteTitle . ' (EN)';

        $settingsData = [
            'tum' => [
                'THIS_OU_NAME' => ['de' => $siteNameDe, 'en' => $siteNameEn],
                'pages' => ['introNaviPid' => 0]
            ],
            'imports' => [['resource' => 'EXT:sitetum/Configuration/Sites/settings.yaml']]
        ];

        // Parent OU nur schreiben, wenn ID gesetzt ist
        if (!empty($config['parentOu'])) {
            $settingsData['tum']['PARENT_OU'] = $config['parentOu'];
            $settingsData['tum']['PARENT_OU_NAME'] = [
                'de' => $config['parentOuNameDe'] ?? '',
                'en' => $config['parentOuNameEn'] ?? ''
            ];
            $settingsData['tum']['PARENT_OU_URL'] = [
                'de' => $config['parentOuUrlDe'] ?? '',
                'en' => $config['parentOuUrlEn'] ?? ''
            ];
        }

        if (!empty($config['news'])) {
            $singlePid = $this->findPageId('Aktuelles', $rootPageId);
            $startingPoint = $this->findPageId('news-system-' . strtolower($config['navName'] ?? ''));
            $settingsData['tum_news'] = ['singlePid' => $singlePid, 'startingpoint' => $startingPoint];
        }

        file_put_contents($configPath . '/settings.yaml', Yaml::dump($settingsData, 99, 2));
    }

    private function createFolderStructure(array $config, ?SymfonyStyle $io): void
    {
        $navName = strtolower($config['navName'] ?? '');
        $wid = strtolower($config['wid'] ?? '');
        if (empty($navName)) return;

        try {
            $storage = $this->storageRepository->findByUid(1);
            if (!$storage) return;
            $rootFolder = $storage->getRootLevelFolder();
            $currentFolder = $rootFolder;

            if (!empty($wid)) {
                $currentFolder = $storage->hasFolder($wid, $rootFolder) ? $rootFolder->getSubfolder($wid) : $storage->createFolder($wid, $rootFolder);
            }
            $currentFolder = $storage->hasFolder($navName, $currentFolder) ? $currentFolder->getSubfolder($navName) : $storage->createFolder($navName, $currentFolder);
            if (!$storage->hasFolder('_my_direct_uploads', $currentFolder)) $storage->createFolder('_my_direct_uploads', $currentFolder);
        } catch (\Exception $e) {}
    }

    private function processData(array $data, array $config, ?SymfonyStyle $io = null): void
    {
        $tableColumnCache = [];
        foreach ($data as $tableName => $rows) {
            if (!is_array($rows) || empty($rows)) continue;
            $connection = $this->connectionPool->getConnectionForTable($tableName);

            if (!isset($tableColumnCache[$tableName])) {
                $schemaManager = $connection->createSchemaManager();
                $validCols = [];
                if ($schemaManager->tablesExist([$tableName])) {
                    $columns = $schemaManager->introspectTable($tableName)->getColumns();
                    foreach ($columns as $column) $validCols[] = strtolower($column->getName());
                }
                $tableColumnCache[$tableName] = $validCols;
            }
            $validColumns = $tableColumnCache[$tableName];
            if (empty($validColumns)) continue;

            $queryBuilder = $connection->createQueryBuilder();
            foreach ($rows as $row) {
                if (isset($row['_condition'])) {
                    if (empty($config[$row['_condition']])) continue;
                    unset($row['_condition']);
                }
                $processedRow = [];
                $validRowData = [];
                foreach ($row as $field => $value) {
                    $processedValue = $this->dataProcessor->process($field, $value, $processedRow, $config, $tableName);
                    $processedRow[$field] = $processedValue;
                    if (in_array(strtolower($field), $validColumns)) $validRowData[$field] = $processedValue;
                }
                if ($tableName === 'be_users' && isset($validRowData['password'])) {
                    $hashInstance = $this->passwordHashFactory->getDefaultHashInstance('BE');
                    $validRowData['password'] = $hashInstance->getHashedPassword($validRowData['password']);
                }
                if (!empty($validRowData)) {
                    try {
                        $queryBuilder->insert($tableName)->values($validRowData)->executeStatement();
                    } catch (\Exception $e) {}
                }
            }
        }
    }

    private function findRootPageId(string $title): int {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        return (int)$qb->select('uid')->from('pages')->where($qb->expr()->eq('is_siteroot', 1), $qb->expr()->eq('title', $qb->createNamedParameter($title)), $qb->expr()->eq('deleted', 0))->setMaxResults(1)->executeQuery()->fetchOne();
    }

    private function findPageId(string $title, int $pid = -1): int {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->select('uid')->from('pages')->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))->andWhere($qb->expr()->eq('deleted', 0))->setMaxResults(1);
        if ($pid !== -1) $qb->andWhere($qb->expr()->eq('pid', $pid));
        return (int)$qb->executeQuery()->fetchOne();
    }
}