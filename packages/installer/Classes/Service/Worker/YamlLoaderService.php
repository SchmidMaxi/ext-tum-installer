<?php
declare(strict_types=1);

namespace Tum\Installer\Service\Worker;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class YamlLoaderService
{
    public function loadAndMerge(string $filePath): array
    {
        $absPath = GeneralUtility::getFileAbsFileName($filePath);
        if (!file_exists($absPath)) {
            throw new \RuntimeException("YAML Datei nicht gefunden: $filePath ($absPath)");
        }

        $data = Yaml::parseFile($absPath);
        if (!is_array($data)) return [];

        $mergedData = [];

        // 1. Imports rekursiv laden
        if (isset($data['imports']) && is_array($data['imports'])) {
            foreach ($data['imports'] as $import) {
                if (empty($import['resource'])) continue;

                $resource = $import['resource'];
                if (!str_starts_with($resource, 'EXT:')) {
                    $resource = rtrim(dirname($filePath), '/') . '/' . ltrim($resource, '/');
                }

                $importedData = $this->loadAndMerge($resource);

                foreach ($importedData as $table => $rows) {
                    $mergedData[$table] = array_merge($mergedData[$table] ?? [], $rows);
                }
            }
            unset($data['imports']);
        }

        // 2. Eigene Daten mergen
        foreach ($data as $table => $rows) {
            $mergedData[$table] = array_merge($mergedData[$table] ?? [], $rows);
        }

        return $mergedData;
    }
}