<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

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

    /**
     * Hauptmethode: Führt den YAML-Import aus
     */
    public function runSetup(string $setupName, array $config): void
    {
        $fileName = 'Configuration/Installer/' . $setupName . '.yaml';
        $setupData = $this->yamlService->parseFile($fileName);

        $this->processData($setupData, $config);

        // OPTIONAL: Wenn wir wissen, dass das Setup fertig ist, können wir die Site Config schreiben.
        // Das kann man hier automatisch machen, oder manuell aus dem Controller aufrufen.
        // Wir machen es hier manuell verfügbar (siehe Methode unten).
    }

    /**
     * Erstellt die TYPO3 Site Configuration (config.yaml) im Dateisystem
     */
    public function createSiteConfiguration(array $config): void
    {
        $navName = $config['navName'] ?? '';
        $domain = $config['domain'] ?? '';

        if (empty($navName) || empty($domain)) {
            // Ohne Namen keine Site Config
            return;
        }

        // 1. Root Page ID finden
        // Wir suchen die Seite, die wir gerade angelegt haben (is_siteroot=1 und Titel = navName)
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rootPageId = (int)$queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('is_siteroot', 1),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(strtolower($navName)))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($rootPageId === 0) {
            // Warnung: Root Seite nicht gefunden, Config kann nicht korrekt erstellt werden
            return;
        }

        // 2. Pfad vorbereiten: config/sites/<navname>/
        $identifier = strtolower($navName);
        $siteConfigPath = Environment::getConfigPath() . '/sites/' . $identifier;

        if (!is_dir($siteConfigPath)) {
            GeneralUtility::mkdir_deep($siteConfigPath);
        }

        // 3. Array Struktur bauen (Minimalistisches v13 Setup)
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

        // 4. YAML schreiben
        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        file_put_contents($siteConfigPath . '/config.yaml', $yamlContent);
    }

    /**
     * Interne Verarbeitung der YAML-Daten
     */
    private function processData(array $data, array $config): void
    {
        // Cache für Spalten-Namen pro Tabelle, um DB-Calls zu sparen
        $tableColumnCache = [];

        foreach ($data as $tableName => $rows) {
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            // 1. Verbindung und Schema holen
            $connection = $this->connectionPool->getConnectionForTable($tableName);

            // Wenn wir die Spalten noch nicht kennen, holen wir sie
            if (!isset($tableColumnCache[$tableName])) {
                $schemaManager = $connection->createSchemaManager();
                $columns = $schemaManager->listTableColumns($tableName);

                // Wir speichern alle Spaltennamen als Kleinbuchstaben
                $validCols = [];
                foreach ($columns as $column) {
                    $validCols[] = strtolower($column->getName());
                }
                $tableColumnCache[$tableName] = $validCols;
            }

            $validColumns = $tableColumnCache[$tableName];
            $queryBuilder = $connection->createQueryBuilder();

            foreach ($rows as $row) {
                $processedRow = [];
                $validRowData = [];

                // 2. Daten verarbeiten (Platzhalter ersetzen etc.)
                foreach ($row as $field => $value) {
                    // Der DataProcessor kümmert sich um Values (Datetime::now, {$wid}, etc.)
                    $processedValue = $this->dataProcessor->process($field, $value, $processedRow, $config, $tableName);

                    // Wir merken uns den Wert für interne Referenzen (z.B. PID Berechnung)
                    $processedRow[$field] = $processedValue;

                    // 3. Prüfen: Gibt es das Feld in der DB?
                    if (in_array(strtolower($field), $validColumns)) {
                        $validRowData[$field] = $processedValue;
                    }
                    // Falls nicht: Einfach ignorieren (das löst dein "slug_locked" Problem)
                }

                // Spezialfall: Passwort Hashen für Backend User
                if ($tableName === 'be_users' && isset($validRowData['password'])) {
                    $hashInstance = $this->passwordHashFactory->getDefaultHashInstance('BE');
                    $validRowData['password'] = $hashInstance->getHashedPassword($validRowData['password']);
                }

                // 4. Insert (nur wenn wir valide Daten haben)
                if (!empty($validRowData)) {
                    $queryBuilder
                        ->insert($tableName)
                        ->values($validRowData)
                        ->executeStatement();
                }
            }
        }
    }
}