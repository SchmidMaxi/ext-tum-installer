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

    /**
     * Hauptmethode zum Ausführen des DB-Setups
     */
    public function runSetup(string $setupName, array $config, ?SymfonyStyle $io = null): void
    {
        // 1. Sicherheitschecks (nur bei Initial-Setup relevant)
        $this->checkPrerequisites($setupName, $config, $io);

        // 2. Ordnerstruktur im fileadmin anlegen
        if ($setupName === 'Setup1' || $setupName === 'Setup3') {
            $this->createFolderStructure($config, $io);
        }

        // 3. Zusätzliche Config-Variablen berechnen
        // Intro Page TSConfig Pfad für YAML-Platzhalter {$introTsConfig}
        if (!empty($config['intropage'])) {
            $config['introTsConfig'] = 'EXT:sitetum/Configuration/PageTs/BackendLayouts/Pagetree/c_intro1col.tsconfig';
        } else {
            $config['introTsConfig'] = '';
        }

        // 4. YAML Import & Verarbeitung
        $fileName = 'Configuration/Installer/' . $setupName . '.yaml';
        $io?->text("--> Lade YAML Datei: $fileName");

        $setupData = $this->yamlService->parseFile($fileName);
        $this->processData($setupData, $config, $io);
    }

    /**
     * Erstellt die komplette Site Configuration (config, csp, settings)
     */
    public function createSiteConfiguration(array $config, string $setupName, ?SymfonyStyle $io = null): void
    {
        $navName = strtolower($config['navName'] ?? '');
        $domain = strtolower($config['domain'] ?? '');

        if (empty($navName) || empty($domain)) {
            $io?->warning("Site Config übersprungen: navName oder domain fehlen.");
            return;
        }

        // Namen vorbereiten (Fallback auf navName)
        $siteNameDe = $config['siteNameDe'] ?? $navName;
        $siteNameEn = $config['siteNameEn'] ?? $navName . ' (EN)';

        // 1. Haupt-Konfiguration (Für Setup 1 & Setup 3)
        // Ziel: sites/<navName>/ (Base: /navName/)
        $io?->section("Erstelle Site Config für Projekt: $navName");
        $this->generateSiteConfigSet(
            $navName,
            $navName, // RootPage Titel = navName (aus WebPages.yaml)
            $domain,
            '/' . $navName . '/',
            $siteNameDe, // Wird in config.yaml websiteTitle und settings.yaml verwendet
            $io,
            $config
        );

        // 2. Domain-Ebene (NUR Setup 1)
        // Ziel: sites/<navName>-d/ (Base: /)
        if ($setupName === 'Setup1') {
            $identifierDomain = $navName . '-d';
            $io?->section("Erstelle Site Config für Domain-Ebene: $identifierDomain");

            $this->generateSiteConfigSet(
                $identifierDomain,
                $domain, // RootPage Titel = Domain (aus WebPagesSystem.yaml)
                $domain,
                '/',
                'Domain Root',
                $io,
                $config
            );
        }
    }

    /**
     * Hilfsmethode: Generiert config.yaml, csp.yaml und settings.yaml
     */
    private function generateSiteConfigSet(
        string $identifier,
        string $rootPageTitle,
        string $domain,
        string $basePath,
        string $websiteTitle,
        ?SymfonyStyle $io,
        array $config
    ): void {
        // Root Page ID suchen
        $rootPageId = $this->findRootPageId($rootPageTitle);

        if ($rootPageId === 0) {
            $io?->warning("Konnte Root Page '$rootPageTitle' nicht finden. Überspringe Config für '$identifier'.");
            return;
        }

        $configPath = Environment::getConfigPath() . '/sites/' . $identifier;
        if (!is_dir($configPath)) {
            GeneralUtility::mkdir_deep($configPath);
        }

        // --- A. config.yaml ---
        $imports = [
            ['resource' => 'EXT:sitetum/Configuration/Sites/base.yaml'],
        ];
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
                [
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'typo3Language' => 'de',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'navigationTitle' => 'Deutsch',
                    'flag' => 'de',
                ],
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/en/',
                    'typo3Language' => 'en',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'navigationTitle' => 'English',
                    'fallbackType' => 'strict',
                    'fallbacks' => '0',
                    'flag' => 'en-us-gb',
                ],
            ],
            'errorHandling' => [
                [
                    'errorCode' => 404,
                    'errorHandler' => 'Page',
                    'errorContentSource' => 't3://page?uid=' . $rootPageId,
                ],
                [
                    'errorCode' => 403,
                    'errorHandler' => 'Page',
                    'errorContentSource' => 't3://page?uid=' . $rootPageId,
                ]
            ],
            'baseVariants' => [
                [
                    'base' => 'https://lu26xap-v12.typo3.lrz.de' . $basePath,
                    'condition' => 'applicationContext == "Production/Testing"'
                ]
            ]
        ];

        file_put_contents($configPath . '/config.yaml', Yaml::dump($configData, 99, 2));
        $io?->text("   [OK] config.yaml erstellt.");

        // --- B. csp.yaml ---
        $cspData = [
            'inheritDefault' => true,
            'imports' => [
                ['resource' => 'EXT:sitetum/Configuration/Sites/csp.yaml']
            ]
        ];
        file_put_contents($configPath . '/csp.yaml', Yaml::dump($cspData, 99, 2));
        $io?->text("   [OK] csp.yaml erstellt.");

        // --- C. settings.yaml ---
        // Namen aus Config holen, sonst Fallback auf websiteTitle
        $siteNameDe = $config['siteNameDe'] ?? $websiteTitle;
        $siteNameEn = $config['siteNameEn'] ?? $websiteTitle . ' (EN)';

        $settingsData = [
            'tum' => [
                'THIS_OU_NAME' => [
                    'de' => $siteNameDe,
                    'en' => $siteNameEn,
                ],
                'PARENT_OU' => '',
                'PARENT_OU_NAME' => ['de' => '', 'en' => ''],
                'PARENT_OU_URL' => ['de' => '', 'en' => ''],
                'pages' => ['introNaviPid' => 0]
            ],
            'imports' => [
                ['resource' => 'EXT:sitetum/Configuration/Sites/settings.yaml']
            ]
        ];

        // Parent OU befüllen (nur wenn Werte vorhanden)
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

        // News IDs befüllen (nur wenn News aktiv)
        if (!empty($config['news'])) {
            // "Aktuelles" sollte unter der RootPage liegen
            $singlePid = $this->findPageId('Aktuelles', $rootPageId);

            // "news-system-..." liegt global im sRoot (System Folder)
            // Wir suchen nach dem Titel, da er unique sein sollte (enthält Kürzel)
            $startingPoint = $this->findPageId('news-system-' . strtolower($config['navName'] ?? ''));

            $settingsData['tum_news'] = [
                'singlePid' => $singlePid,
                'startingpoint' => $startingPoint,
            ];
        }

        file_put_contents($configPath . '/settings.yaml', Yaml::dump($settingsData, 99, 2));
        $io?->text("   [OK] settings.yaml erstellt.");
    }

    /**
     * Erstellt die Ordnerstruktur im fileadmin (Storage 1)
     */
    private function createFolderStructure(array $config, ?SymfonyStyle $io): void
    {
        $navName = strtolower($config['navName'] ?? '');
        $wid = strtolower($config['wid'] ?? '');

        if (empty($navName)) {
            return;
        }

        try {
            // V14: StorageRepository statt ResourceFactory
            $storage = $this->storageRepository->findByUid(1);

            if ($storage === null) {
                $io?->warning("Storage 1 (fileadmin) nicht gefunden! Kann Ordner nicht anlegen.");
                return;
            }

            $rootFolder = $storage->getRootLevelFolder();
            $currentFolder = $rootFolder;

            // 1. Ebene: WID (falls vorhanden)
            if (!empty($wid)) {
                if (!$storage->hasFolder($wid, $rootFolder)) {
                    $io?->text("--> Erstelle Ordner: /$wid");
                    $currentFolder = $storage->createFolder($wid, $rootFolder);
                } else {
                    $currentFolder = $rootFolder->getSubfolder($wid);
                }
            }

            // 2. Ebene: navName (Kürzel)
            if (!$storage->hasFolder($navName, $currentFolder)) {
                $io?->text("--> Erstelle Ordner: " . $currentFolder->getIdentifier() . $navName);
                $currentFolder = $storage->createFolder($navName, $currentFolder);
            } else {
                $currentFolder = $currentFolder->getSubfolder($navName);
            }

            // 3. Ebene: _my_direct_uploads
            $uploadFolder = '_my_direct_uploads';
            if (!$storage->hasFolder($uploadFolder, $currentFolder)) {
                $io?->text("--> Erstelle Ordner: " . $currentFolder->getIdentifier() . $uploadFolder);
                $storage->createFolder($uploadFolder, $currentFolder);
            }

        } catch (\Exception $e) {
            $io?->warning("Konnte Ordnerstruktur nicht vollständig anlegen: " . $e->getMessage());
        }
    }

    /**
     * Prüft Voraussetzungen (z.B. ob Projekt schon existiert)
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

    /**
     * Verarbeitet die YAML-Daten und schreibt in die DB
     */
    private function processData(array $data, array $config, ?SymfonyStyle $io = null): void
    {
        $tableColumnCache = [];

        foreach ($data as $tableName => $rows) {
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $connection = $this->connectionPool->getConnectionForTable($tableName);

            // Schema Caching für V14 (introspectTable)
            if (!isset($tableColumnCache[$tableName])) {
                $schemaManager = $connection->createSchemaManager();
                $validCols = [];
                if ($schemaManager->tablesExist([$tableName])) {
                    $columns = $schemaManager->introspectTable($tableName)->getColumns();
                    foreach ($columns as $column) {
                        $validCols[] = strtolower($column->getName());
                    }
                }
                $tableColumnCache[$tableName] = $validCols;
            }

            $validColumns = $tableColumnCache[$tableName];
            if (empty($validColumns)) {
                $io?->warning("Tabelle '$tableName' nicht gefunden oder leer. Überspringe.");
                continue;
            }

            $queryBuilder = $connection->createQueryBuilder();

            foreach ($rows as $row) {
                // FEATURE: Conditional Import (_condition)
                if (isset($row['_condition'])) {
                    $conditionKey = $row['_condition'];
                    if (empty($config[$conditionKey])) {
                        continue; // Überspringen, wenn Config nicht true ist
                    }
                    unset($row['_condition']);
                }

                $processedRow = [];
                $validRowData = [];

                foreach ($row as $field => $value) {
                    $processedValue = $this->dataProcessor->process($field, $value, $processedRow, $config, $tableName);
                    $processedRow[$field] = $processedValue;

                    // Nur schreiben, wenn Spalte existiert
                    if (in_array(strtolower($field), $validColumns)) {
                        $validRowData[$field] = $processedValue;
                    }
                }

                // Passwörter hashen
                if ($tableName === 'be_users' && isset($validRowData['password'])) {
                    $hashInstance = $this->passwordHashFactory->getDefaultHashInstance('BE');
                    $validRowData['password'] = $hashInstance->getHashedPassword($validRowData['password']);
                }

                if (!empty($validRowData)) {
                    try {
                        $queryBuilder
                            ->insert($tableName)
                            ->values($validRowData)
                            ->executeStatement();
                    } catch (\Exception $e) {
                        $io?->error("Fehler beim Insert in $tableName: " . $e->getMessage());
                    }
                }
            }
        }
    }

    // --- Helper ---

    private function findRootPageId(string $title): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        return (int)$qb->select('uid')->from('pages')
            ->where(
                $qb->expr()->eq('is_siteroot', 1),
                $qb->expr()->eq('title', $qb->createNamedParameter($title)),
                $qb->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private function findPageId(string $title, int $pid = -1): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->select('uid')
            ->from('pages')
            ->where($qb->expr()->eq('title', $qb->createNamedParameter($title)))
            ->andWhere($qb->expr()->eq('deleted', 0))
            ->setMaxResults(1);

        if ($pid !== -1) {
            $qb->andWhere($qb->expr()->eq('pid', $pid));
        }

        return (int)$qb->executeQuery()->fetchOne();
    }
}