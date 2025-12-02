<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class SetupService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private YamlService $yamlService,
        private DataProcessingService $dataProcessor,
        private PasswordHashFactory $passwordHashFactory
    ) {}

    public function runSetup(string $setupName, array $config, ?SymfonyStyle $io = null): void
    {
        $fileName = 'Configuration/Installer/' . $setupName . '.yaml';
        $io?->text("--> Lade YAML Datei: $fileName");

        $setupData = $this->yamlService->parseFile($fileName);

        $io?->text("--> YAML geladen. Starte Verarbeitung von " . count($setupData) . " Tabellen-Blöcken.");

        $this->processData($setupData, $config, $io);
    }

    public function createSiteConfiguration(array $config, ?SymfonyStyle $io = null): void
    {
        $navName = $config['navName'] ?? '';
        $domain = $config['domain'] ?? '';

        if (empty($navName) || empty($domain)) {
            $io?->warning("Überspringe Site Config: navName oder domain fehlen.");
            return;
        }

        $io?->text("--> Suche Root Page mit Titel: " . strtolower($navName));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rootPageId = (int)$queryBuilder->select('uid')->from('pages')
            ->where(
                $queryBuilder->expr()->eq('is_siteroot', 1),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(strtolower($navName)))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($rootPageId === 0) {
            $io?->warning("KEINE Root Page gefunden! Kann Site Config nicht anlegen. (Hast du die Seite 'Startseite' oder passend zum navName angelegt?)");
            return;
        }

        $io?->text("--> Root Page ID gefunden: $rootPageId");

        $identifier = strtolower($navName);
        $siteConfigPath = Environment::getConfigPath() . '/sites/' . $identifier;

        if (!is_dir($siteConfigPath)) {
            $io?->text("--> Erstelle Verzeichnis: $siteConfigPath");
            GeneralUtility::mkdir_deep($siteConfigPath);
        }

        $siteConfiguration = [
            'rootPageId' => $rootPageId,
            'base' => 'https://' . $domain . '/',
            'websiteTitle' => $navName,
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'typo3Language' => 'de',
                    'locale' => 'de_DE.UTF-8',
                    'navigationTitle' => 'Deutsch',
                ],
            ],
            'routeEnhancers' => [
                'PageTypeSuffix' => [
                    'type' => 'PageType',
                    'default' => '/',
                    'index' => '',
                    'map' => [
                        '/' => 0
                    ]
                ]
            ],
        ];

        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        file_put_contents($siteConfigPath . '/config.yaml', $yamlContent);

        $io?->success("Config Datei geschrieben nach: $siteConfigPath/config.yaml");
    }

    private function processData(array $data, array $config, ?SymfonyStyle $io = null): void
    {
        $tableColumnCache = [];

        foreach ($data as $tableName => $rows) {
            if (!is_array($rows) || empty($rows)) {
                $io?->text("--> Überspringe Tabelle '$tableName' (leer oder kein Array)");
                continue;
            }

            $io?->section("Bearbeite Tabelle: $tableName");

            $connection = $this->connectionPool->getConnectionForTable($tableName);
            $schemaManager = $connection->createSchemaManager();

            if (!$schemaManager->tablesExist([$tableName])) {
                $io?->error("ACHTUNG: Tabelle '$tableName' existiert nicht in der Datenbank! Überspringe.");
                continue;
            }

            if (!isset($tableColumnCache[$tableName])) {
                $validCols = [];
                // Debugging für Spalten
                try {
                    $columns = $schemaManager->introspectTable($tableName)->getColumns();
                    foreach ($columns as $column) {
                        $validCols[] = strtolower($column->getName());
                    }
                    $io?->text("--> Gefundene Spalten in DB: " . implode(', ', $validCols));
                } catch (\Exception $e) {
                    $io?->error("Fehler beim Lesen der Spalten für $tableName: " . $e->getMessage());
                    continue;
                }

                $tableColumnCache[$tableName] = $validCols;
            }

            $validColumns = $tableColumnCache[$tableName];
            if (empty($validColumns)) {
                $io?->warning("--> Keine Spalten für '$tableName' gefunden? Überspringe.");
                continue;
            }

            $queryBuilder = $connection->createQueryBuilder();
            $rowCount = 0;

            foreach ($rows as $row) {
                $rowCount++;

                // Condition Check
                if (isset($row['_condition'])) {
                    $conditionKey = $row['_condition'];
                    if (empty($config[$conditionKey])) {
                        $io?->text("  - Zeile $rowCount übersprungen (Condition '$conditionKey' nicht erfüllt)");
                        continue;
                    }
                    unset($row['_condition']);
                }

                $processedRow = [];
                $validRowData = [];

                foreach ($row as $field => $value) {
                    $processedValue = $this->dataProcessor->process($field, $value, $processedRow, $config, $tableName);
                    $processedRow[$field] = $processedValue;

                    if (in_array(strtolower($field), $validColumns)) {
                        $validRowData[$field] = $processedValue;
                    } else {
                        // Verbose output, wenn Felder ignoriert werden
                        if ($io?->isVerbose()) {
                            $io->text("    ! Ignoriere Feld '$field' (existiert nicht in DB)");
                        }
                    }
                }

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

                        // Kurze Info bei Erfolg
                        if ($io?->isVerbose()) {
                            $io->text("  + Zeile $rowCount eingefügt.");
                        }
                    } catch (\Exception $e) {
                        $io?->error("  X Fehler beim Insert Zeile $rowCount: " . $e->getMessage());
                    }
                } else {
                    $io?->warning("  ! Zeile $rowCount hat keine validen Daten zum Speichern.");
                }
            }
            $io?->text("--> $rowCount Zeilen verarbeitet.");
        }
    }
}