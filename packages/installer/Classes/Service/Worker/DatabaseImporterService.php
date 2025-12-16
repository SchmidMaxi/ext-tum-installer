<?php
declare(strict_types=1);

namespace Tum\Installer\Service\Worker;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Service\Helper\DataProcessingService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;

class DatabaseImporterService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly DataProcessingService $dataProcessor, // Dein alter Service
        private readonly PasswordHashFactory $passwordHashFactory
    ) {}

    public function import(array $data, InstallationConfig $config): void
    {
        $configArray = $config->toArray(); // Legacy Support für DataProcessor

        foreach ($data as $tableName => $rows) {
            if (!is_array($rows) || empty($rows) || $tableName === 'imports') continue;

            $connection = $this->connectionPool->getConnectionForTable($tableName);

            // Spalten prüfen
            $schemaManager = $connection->createSchemaManager();
            if (!$schemaManager->tablesExist([$tableName])) continue;

            $validCols = [];
            foreach ($schemaManager->introspectTable($tableName)->getColumns() as $col) {
                $validCols[] = strtolower($col->getName());
            }

            $queryBuilder = $connection->createQueryBuilder();
            foreach ($rows as $row) {
                // Conditions prüfen
                if (isset($row['_condition'])) {
                    $cond = $row['_condition'];
                    // Prüfen ob Flag im Config Objekt true ist (via Array Access)
                    if (empty($configArray[$cond])) continue;
                    unset($row['_condition']);
                }

                $validRowData = [];
                $processedRow = []; // Context für DataProcessor

                foreach ($row as $field => $value) {
                    // Werte verarbeiten ({$navName} etc.)
                    $processedValue = $this->dataProcessor->process($field, $value, $processedRow, $configArray, $tableName);
                    $processedRow[$field] = $processedValue;

                    if (in_array(strtolower($field), $validCols)) {
                        $validRowData[$field] = $processedValue;
                    }
                }

                // Password Hashing
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
}