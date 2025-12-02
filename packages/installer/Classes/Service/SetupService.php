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
        // SCHRITT 1: Prüfen, ob wir das überhaupt dürfen
        $this->checkPrerequisites($setupName, $config, $io);

        $fileName = 'Configuration/Installer/' . $setupName . '.yaml';
        $io?->text("--> Lade YAML Datei: $fileName");

        $setupData = $this->yamlService->parseFile($fileName);
        $this->processData($setupData, $config, $io);
    }

    /**
     * Prüft, ob Setup 1 ausgeführt werden darf.
     */
    private function checkPrerequisites(string $setupName, array $config, ?SymfonyStyle $io): void
    {
        // Wir prüfen nur bei "Setup1" (Initial-Setup)
        if ($setupName !== 'Setup1') {
            return;
        }

        $navName = $config['navName'] ?? '';
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        // Check A: Existiert das Projekt ($navName) schon?
        if (!empty($navName)) {
            $projectExists = (bool)$queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(strtolower($navName))),
                    $queryBuilder->expr()->eq('deleted', 0)
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($projectExists) {
                $msg = sprintf("ABBRUCH: Das Projekt '%s' existiert bereits! Bitte nutze Setup 3 für Updates.", $navName);
                $io?->error($msg);
                // Harter Abbruch via Exception, damit auch der Controller das mitbekommt
                throw new \RuntimeException($msg);
            }
        }

        // Check B: Existiert sRoot schon? (Indikator für globale Installation)
        // Hier müssen wir aufpassen: Wenn sRoot existiert, darf man vielleicht trotzdem
        // ein ZWEITES Projekt (W02) anlegen?
        // Laut deiner Anforderung: "Es soll nur einmal ein Setup 1 möglich sein."
        $sRootExists = (bool)$queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter('sRoot')),
                $queryBuilder->expr()->eq('pid', 0),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if ($sRootExists) {
            // Wenn sRoot da ist, aber wir ein neues Projekt anlegen wollen, ist das okay?
            // Deine Anforderung sagt: "Setup 1 nur einmal".
            // Also werfen wir hier einen Fehler.
            $msg = "ABBRUCH: Die globale Struktur 'sRoot' existiert bereits. Setup 1 darf nicht erneut ausgeführt werden.";
            $io?->error($msg);
            throw new \RuntimeException($msg);
        }
    }

    // ... (Rest der Datei createSiteConfiguration und processData bleibt identisch wie vorher) ...
    public function createSiteConfiguration(array $config, ?SymfonyStyle $io = null): void
    {
        $navName = $config['navName'] ?? '';
        $domain = $config['domain'] ?? '';
        if (empty($navName) || empty($domain)) return;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rootPageId = (int)$queryBuilder->select('uid')->from('pages')
            ->where($queryBuilder->expr()->eq('is_siteroot', 1), $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(strtolower($navName))))
            ->setMaxResults(1)->executeQuery()->fetchOne();

        if ($rootPageId === 0) {
            $io?->warning("KEINE Root Page gefunden! Kann Site Config nicht anlegen.");
            return;
        }

        $identifier = strtolower($navName);
        $siteConfigPath = Environment::getConfigPath() . '/sites/' . $identifier;
        if (!is_dir($siteConfigPath)) GeneralUtility::mkdir_deep($siteConfigPath);

        $siteConfiguration = [
            'rootPageId' => $rootPageId,
            'base' => 'https://' . $domain . '/',
            'websiteTitle' => $navName,
            'languages' => [['title' => 'Deutsch', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'typo3Language' => 'de', 'locale' => 'de_DE.UTF-8', 'navigationTitle' => 'Deutsch']],
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType', 'default' => '/', 'index' => '', 'map' => ['/' => 0]]]
        ];
        file_put_contents($siteConfigPath . '/config.yaml', Yaml::dump($siteConfiguration, 99, 2));
        $io?->success("Site Config erstellt.");
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
                    if (in_array(strtolower($field), $validColumns)) {
                        $validRowData[$field] = $processedValue;
                    }
                }

                if ($tableName === 'be_users' && isset($validRowData['password'])) {
                    $hashInstance = $this->passwordHashFactory->getDefaultHashInstance('BE');
                    $validRowData['password'] = $hashInstance->getHashedPassword($validRowData['password']);
                }

                if (!empty($validRowData)) {
                    try {
                        $queryBuilder->insert($tableName)->values($validRowData)->executeStatement();
                    } catch (\Exception $e) {
                        // Silent fail or log
                    }
                }
            }
        }
    }
}