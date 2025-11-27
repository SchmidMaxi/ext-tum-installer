<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Liest YAML-Dateien ein und löst "imports" auf.
 */
class YamlService
{
    public function parseFile(string $file, bool $recursiveMergeImports = true): array
    {
        // 1. Pfad auflösen (Absolut machen)
        if (!str_starts_with($file, '/')) {
            $file = ExtensionManagementUtility::extPath('installer') . $file;
        }

        if (!file_exists($file)) {
            throw new \RuntimeException('YAML file not found: ' . $file);
        }

        $data = Yaml::parseFile($file);

        if ($recursiveMergeImports && isset($data['imports']) && is_array($data['imports'])) {
            foreach ($data['imports'] as $import) {
                $resource = $import['resource'];

                // VERSUCH 1: Relativ zur aktuellen Datei (Standard)
                $importPath = dirname($file) . '/' . $resource;

                // VERSUCH 2: Fallback für alte Pfade (Wenn sie mit Installer/ anfangen)
                if (!file_exists($importPath) && str_starts_with($resource, 'Installer/')) {
                    // Wir bauen den Pfad so um, dass er relativ zu Configuration/ ist
                    $extensionRoot = ExtensionManagementUtility::extPath('installer');
                    $importPath = $extensionRoot . 'Configuration/' . $resource;
                }

                $importedData = $this->parseFile($importPath);
                $data = array_replace_recursive($importedData, $data);
            }
            unset($data['imports']);
        }

        return $data;
    }
}