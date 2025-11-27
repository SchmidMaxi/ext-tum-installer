<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class SetupService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private YamlService $yamlService,
        private DataProcessingService $dataProcessor, // <-- NEU
        private PasswordHashFactory $passwordHashFactory // <-- Für Passwörter
    ) {}

    public function runSetup(string $setupName, array $config = []): void
    {
        // Falls keine Config übergeben wurde, Dummy-Werte (zum Testen)
        if (empty($config)) {
            $config = [
                'navName' => 'TestPage',
                'domain' => 'test.tum.de',
                'wid' => 'w123'
            ];
        }

        $fileName = 'Configuration/Installer/' . $setupName . '.yaml';
        $setupData = $this->yamlService->parseFile($fileName);

        $this->processData($setupData, $config);
    }

    private function processData(array $data, array $config): void
    {
        foreach ($data as $tableName => $rows) {
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);

            foreach ($rows as $row) {
                $processedRow = [];

                // 1. Daten verarbeiten (Platzhalter auflösen)
                foreach ($row as $field => $value) {
                    // Wir übergeben $processedRow, damit wir z.B. auf 'pid' zugreifen können,
                    // falls das Feld danach 'sorting' ist.
                    $processedRow[$field] = $this->dataProcessor->process(
                        $field,
                        $value,
                        $processedRow,
                        $config,
                        $tableName
                    );
                }

                // 2. Spezialfall: Passwörter hashen (be_users)
                if ($tableName === 'be_users' && isset($processedRow['password'])) {
                    $hashInstance = $this->passwordHashFactory->getDefaultHashInstance('BE');
                    $processedRow['password'] = $hashInstance->getHashedPassword($processedRow['password']);
                }

                // 3. Insert
                $queryBuilder
                    ->insert($tableName)
                    ->values($processedRow)
                    ->executeStatement();
            }
        }
    }
}