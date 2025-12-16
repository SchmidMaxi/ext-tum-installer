<?php
declare(strict_types=1);

namespace Tum\Installer\Service\Worker;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Service\Helper\DataProcessingService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility; // Wichtig für trimExplode

class DatabaseImporterService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly DataProcessingService $dataProcessor,
        private readonly PasswordHashFactory $passwordHashFactory
    ) {}

    public function import(array $data, InstallationConfig $config): void
    {
        $configArray = $config->toArray();

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

                // ---------------------------------------------------------
                // UPDATE: Conditions mit AND (&&) Support prüfen
                // ---------------------------------------------------------
                if (isset($row['_condition'])) {
                    $conditionString = (string)$row['_condition'];

                    // Zerlege String wie "news && intropage" in Einzelteile
                    $conditions = GeneralUtility::trimExplode('&&', $conditionString, true);

                    $skipRow = false;
                    foreach ($conditions as $cond) {
                        // Wenn AUCH NUR EINE Bedingung nicht im Config-Array ist (oder false ist) -> Überspringen
                        if (empty($configArray[$cond])) {
                            $skipRow = true;
                            break;
                        }
                    }

                    if ($skipRow) continue;
                    unset($row['_condition']);
                }
                // ---------------------------------------------------------

                $validRowData = [];
                $processedRow = [];

                foreach ($row as $field => $value) {
                    $processedValue = $this->dataProcessor->process($field, $value, $processedRow, $configArray, $tableName);
                    $processedRow[$field] = $processedValue;

                    if (in_array(strtolower($field), $validCols)) {
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
                    } catch (\Exception $e) {}
                }
            }
        }
    }
}