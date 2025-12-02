<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class YamlService
{
    public function parseFile(string $file, bool $recursiveMergeImports = true): array
    {
        // 1. Pfad auflösen
        if (!str_starts_with($file, '/')) {
            // KORREKTUR: Wir hängen nur den Extension-Pfad davor.
            // Der Aufrufer (SetupService) liefert bereits "Configuration/..." mit.
            $file = ExtensionManagementUtility::extPath('installer') . $file;
        }

        if (!file_exists($file)) {
            throw new \RuntimeException('YAML file not found: ' . $file);
        }

        $data = Yaml::parseFile($file);

        if ($recursiveMergeImports && isset($data['imports']) && is_array($data['imports'])) {
            foreach ($data['imports'] as $import) {
                $resource = $import['resource'];

                // Relativen Pfad auflösen (relativ zur aktuellen Datei)
                $importPath = dirname($file) . '/' . $resource;

                // Fallback für alte Pfade (die mit Installer/ beginnen)
                if (!file_exists($importPath) && str_starts_with($resource, 'Installer/')) {
                    // Hier müssen wir "Configuration/" explizit nutzen, da "Installer/"
                    // meist unterhalb von Configuration liegt.
                    $importPath = ExtensionManagementUtility::extPath('installer') . 'Configuration/' . $resource;
                }

                // Prüfen ob Import existiert, sonst Warnung oder Fehler
                if (!file_exists($importPath)) {
                    // Optional: Logging/Warning hier, aber RuntimeException ist sicher
                    // throw new \RuntimeException("Import file not found: $importPath");
                }

                $importedData = $this->parseFile($importPath);

                // Merge Logic (Zeilen anfügen statt überschreiben)
                $data = $this->mergeDatasets($importedData, $data);
            }
            unset($data['imports']);
        }

        return $data;
    }

    /**
     * Spezialisierter Merge für Installer-YAMLs.
     */
    private function mergeDatasets(array $imported, array $current): array
    {
        foreach ($imported as $key => $value) {
            if (isset($current[$key])) {
                // Wenn beides Listen sind -> Anfügen (merge)
                if (is_array($value) && is_array($current[$key]) && !empty($value)) {
                    if (array_is_list($value) && array_is_list($current[$key])) {
                        $current[$key] = array_merge($current[$key], $value);
                    } else {
                        $current[$key] = array_replace_recursive($current[$key], $value);
                    }
                } else {
                    $current[$key] = $value;
                }
            } else {
                $current[$key] = $value;
            }
        }
        return $current;
    }
}