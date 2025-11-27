<?php
declare(strict_types=1);

namespace Tum\Installer\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DataProcessingService
{
    private const SORTING_STEPS = 256;

    // Mapping für TYPO3 Doktypes
    private const DOKTYPE_MAP = [
        'default' => 1,
        'standard' => 1,
        'link' => 3,
        'shortcut' => 4,
        'mountpoint' => 7,
        'spacer' => 199,
        'folder' => 254,
        'recycler' => 255,
    ];

    // Mapping für Shortcut Modus
    private const SHORTCUT_MODE_MAP = [
        'default' => 0,
        'first_subpage' => 1,
        'random_subpage' => 2,
        'parent' => 3,
    ];

    // Mapping für Berechtigungen
    private const PERMISSIONS_MAP = [
        'show_page' => 1,
        'edit_page' => 2,
        'delete_page' => 4,
        'new_pages' => 8,
        'edit_content' => 16,
    ];

    // Mapping für SysTemplate "Clear" Flags
    private const CLEAR_CACHE_MAP = [
        'constants' => 1,
        'setup' => 2,
    ];

    public function __construct(
        private readonly TypeService $typeService,
        private readonly ConnectionPool $connectionPool
    ) {}

    public function process(string $property, mixed $value, array $processedRow, array $config, string $tableName): mixed
    {
        // 1. Spezielle Mappings
        if ($property === 'doktype' && is_string($value)) {
            return $this->resolveDoktype($value);
        }
        if ($property === 'shortcut_mode' && is_string($value)) {
            return $this->resolveShortcutMode($value);
        }

        $type = $this->typeService->getType($value);

        return match ($type) {
            TypeService::TYPE_BINARY_ARRAY => $this->resolveBinaryArray($property, $value),
            TypeService::TYPE_DATETIME => $this->getUnixTimeStamp($value),
            TypeService::TYPE_DATABASE => $this->resolveDatabaseOperation($value),
            TypeService::TYPE_CONFIG => $this->resolveConfig($value, $config),
            TypeService::TYPE_SORTING => $this->resolveSorting($value, $processedRow, $tableName, $config),
            TypeService::TYPE_MIXED_STRING => $this->resolveMixedString($property, $value, $processedRow, $config, $tableName),
            default => $value,
        };
    }

    // --- Mappings ---

    private function resolveDoktype(string $value): int
    {
        $key = strtolower($value);
        return self::DOKTYPE_MAP[$key] ?? (is_numeric($value) ? (int)$value : 1);
    }

    private function resolveShortcutMode(string $value): int
    {
        $key = strtolower($value);
        return self::SHORTCUT_MODE_MAP[$key] ?? (is_numeric($value) ? (int)$value : 0);
    }

    private function resolveBinaryArray(string $property, array $values): int
    {
        $bitmask = 0;
        $map = [];

        if (str_starts_with($property, 'perms_')) {
            $map = self::PERMISSIONS_MAP;
        } elseif ($property === 'clear') {
            $map = self::CLEAR_CACHE_MAP;
        }

        foreach ($values as $item) {
            if (isset($map[$item])) {
                $bitmask += $map[$item];
            }
        }
        return $bitmask;
    }

    // --- Typ-Verarbeitung ---

    private function getUnixTimeStamp(string $value): int
    {
        $dateValue = substr($value, 10);
        return (new \DateTime($dateValue))->getTimestamp();
    }

    private function resolveDatabaseOperation(string $value): mixed
    {
        $content = substr($value, 5, -1);
        $parts = GeneralUtility::trimExplode('::', $content, true);
        if (count($parts) < 4) return 0;

        $table = $parts[0];
        $searchField = $parts[1];
        $searchValue = $parts[2];
        $selectField = $parts[3];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder
            ->select($selectField)
            ->from($table)
            ->where($queryBuilder->expr()->eq($searchField, $queryBuilder->createNamedParameter($searchValue)))
            ->setMaxResults(1);

        for ($i = 4; $i < count($parts); $i += 2) {
            if (isset($parts[$i + 1])) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($parts[$i], $queryBuilder->createNamedParameter($parts[$i + 1]))
                );
            }
        }

        $result = $queryBuilder->executeQuery()->fetchOne();

        // Logik aus altem Installer: Wenn nichts gefunden wird -> Fehler oder 0?
        // Für perms_groupid ist 0 ein valider Fallback ("keine Gruppe"),
        // verhindert aber den SQL-Crash beim Insert.
        return $result === false ? 0 : $result;
    }

    private function resolveConfig(string $value, array $config): mixed
    {
        $content = substr($value, 2, -1);
        if (str_contains($content, '->')) {
            [$key, $method] = GeneralUtility::trimExplode('->', $content);
            $method = rtrim($method, '()');
            $val = $config[$key] ?? '';

            if ($method === 'strtolower') return strtolower((string)$val);
            if ($method === 'strtoupper') return strtoupper((string)$val);
            if ($method === 'formatImprint') return nl2br((string)$val);
            return $val;
        }
        return $config[$content] ?? '';
    }

    /**
     * WICHTIGSTER FIX: Rekursive Verarbeitung
     */
    private function resolveMixedString(string $property, string $value, array $processedRow, array $config, string $tableName): mixed
    {
        // 1. Suche nach inneren Klammern { ... }
        preg_match_all('/{([^{]*?)}/', $value, $matches);

        if (!empty($matches[0])) {
            foreach ($matches[0] as $match) {
                // Den inneren Teil auflösen (z.B. {$wid} -> W123)
                $replacement = $this->process($property, $match, $processedRow, $config, $tableName);
                $value = str_replace($match, (string)$replacement, $value);
            }
        }

        // 2. KORREKTUR: Den neuen String NOCHMAL prüfen!
        // Wenn aus '...{$wid}...' jetzt '{db::...}' geworden ist, muss das auch verarbeitet werden.
        // Wir rufen process rekursiv auf.

        // Um Endlosschleifen zu vermeiden, prüfen wir, ob sich der Wert geändert hat
        // oder ob er jetzt als anderer Typ erkannt wird.
        $newType = $this->typeService->getType($value);

        if ($newType !== TypeService::TYPE_STRING && $newType !== TypeService::TYPE_MIXED_STRING) {
            // Es ist jetzt z.B. ein TYPE_DATABASE geworden -> Auflösen!
            return $this->process($property, $value, $processedRow, $config, $tableName);
        }

        // Fallback: Wenn immer noch Mixed String (weil z.B. geschachtelt), weiter rekursiv.
        // Im alten Code wurde immer recursed. Das machen wir hier auch, wenn noch Klammern da sind.
        if (str_contains($value, '{') && str_contains($value, '}')) {
            // ACHTUNG: Hier brauchen wir eine Abbruchbedingung, falls es einfach nur Text ist.
            // Der TypeService klassifiziert "Text {ohne} Sinn" als String, wenn keine Keywords vorkommen.
            // Daher ist der Check oben ($newType) eigentlich sicher.

            // Einziges Risiko: Nested Mixed Strings.
            // Prüfen wir, ob noch Keywords drin sind, die TypeService als "Mixed" erkennt.
            if ($newType === TypeService::TYPE_MIXED_STRING) {
                return $this->process($property, $value, $processedRow, $config, $tableName);
            }
        }

        return $value;
    }

    private function resolveSorting(string $value, array $processedRow, string $tableName, array $config): int
    {
        $mode = substr($value, 9);
        $pid = $processedRow['pid'] ?? 0;
        $qb = $this->connectionPool->getQueryBuilderForTable($tableName);

        if ($mode === 'next') {
            $max = $qb->selectLiteral('MAX(sorting)')
                ->from($tableName)
                ->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid)))
                ->executeQuery()
                ->fetchOne();
            return (int)$max + self::SORTING_STEPS;
        }
        return self::SORTING_STEPS;
    }
}