<?php
declare(strict_types=1);

namespace Tum\Installer\Service\Helper;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DataProcessingService
{
    public const TYPE_STRING = 'string';
    public const TYPE_MIXED_STRING = 'mixed_string';
    public const TYPE_BINARY_ARRAY = 'binary_array';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_DATABASE = 'database';
    public const TYPE_CONFIG = 'config';
    public const TYPE_SORTING = 'sorting';

    private const SORTING_STEPS = 256;

    private const DOKTYPE_MAP = [
        'default' => 1,
        'standard' => 1,
        'link' => 3,
        'shortcut' => 4,
        'mountpoint' => 7,
        'spacer' => 199,
        'folder' => 254,
        'recycler' => 255
    ];
    private const SHORTCUT_MODE_MAP = [
        'default' => 0,
        'first_subpage' => 1,
        'random_subpage' => 2,
        'parent' => 3
    ];
    private const PERMISSIONS_MAP = [
        'show_page' => 1,
        'edit_page' => 2,
        'delete_page' => 4,
        'new_pages' => 8,
        'edit_content' => 16
    ];
    private const CLEAR_CACHE_MAP = [
        'constants' => 1,
        'setup' => 2
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    private function getType(mixed $value): string
    {
        if (is_array($value)) {
            return self::TYPE_BINARY_ARRAY;
        }
        if (!is_string($value)) {
            return self::TYPE_STRING;
        }

        $val = trim($value);

        if (str_starts_with($val, 'Datetime::')) {
            return self::TYPE_DATETIME;
        }
        if (str_starts_with($val, 'Sorting::')) {
            return self::TYPE_SORTING;
        }

        if (preg_match('/^{db::[^{}]+}$/', $val)) {
            return self::TYPE_DATABASE;
        }

        if (preg_match('/^{\$[a-zA-Z0-9_]+( -> .*)?}$/', $val)) {
            return self::TYPE_CONFIG;
        }

        if (str_contains($val, '{')) {
            return self::TYPE_MIXED_STRING;
        }

        return self::TYPE_STRING;
    }

    public function process(string $property, mixed $value, array $processedRow, array $config, string $tableName): mixed
    {
        if (is_string($value)) $value = trim($value);
        if ($property === 'doktype' && is_string($value)) return $this->resolveDoktype($value);
        if ($property === 'shortcut_mode' && is_string($value)) return $this->resolveShortcutMode($value);

        $type = $this->getType($value);

        return match ($type) {
            self::TYPE_BINARY_ARRAY => $this->resolveBinaryArray($property, $value),
            self::TYPE_DATETIME => $this->getUnixTimeStamp($value),
            self::TYPE_DATABASE => $this->resolveDatabaseOperation($value),
            self::TYPE_CONFIG => $this->resolveConfig($value, $config),
            self::TYPE_SORTING => $this->resolveSorting($value, $processedRow, $tableName, $config),
            self::TYPE_MIXED_STRING => $this->resolveMixedString($property, $value, $processedRow, $config, $tableName),
            default => $value,
        };
    }

    private function resolveDoktype(string $value): int
    {
        $key = strtolower(trim($value));
        return self::DOKTYPE_MAP[$key] ?? (is_numeric($value) ? (int)$value : 1);
    }

    private function resolveShortcutMode(string $value): int
    {
        $key = strtolower(trim($value));
        return self::SHORTCUT_MODE_MAP[$key] ?? (is_numeric($value) ? (int)$value : 0);
    }

    private function resolveBinaryArray(string $property, array $values): int
    {
        $bitmask = 0;
        $map = str_starts_with($property, 'perms_') ? self::PERMISSIONS_MAP : ( $property === 'clear' ? self::CLEAR_CACHE_MAP : [] );
        foreach ($values as $item)
            if (isset($map[$item])) $bitmask += $map[$item];
        return $bitmask;
    }

    private function getUnixTimeStamp(string $value): int
    {
        return (new \DateTime(substr($value, 10)))->getTimestamp();
    }

    private function resolveDatabaseOperation(string $value): mixed {
        $content = substr($value, 5, -1);
        $parts = GeneralUtility::trimExplode('::', $content, true);
        if (count($parts) < 4) return 0;

        $table = $parts[0]; $searchField = $parts[1]; $searchValue = $parts[2]; $selectField = $parts[3];

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->select($selectField)
            ->from($table)
            ->where($qb->expr()->eq($searchField, $qb->createNamedParameter($searchValue)))
            ->andWhere($qb->expr()->eq('deleted', 0))
            ->setMaxResults(1);

        for ($i = 4; $i < count($parts); $i += 2) {
            if (isset($parts[$i + 1])) {
                $qb->andWhere($qb->expr()->eq($parts[$i], $qb->createNamedParameter($parts[$i + 1])));
            }
        }

        $result = $qb->executeQuery()->fetchOne();
        return $result === false ? 0 : $result;
    }

    private function resolveConfig(string $value, array $config): mixed {
        $content = trim(substr($value, 2, -1));
        if (str_contains($content, '->')) {
            [$key, $method] = GeneralUtility::trimExplode('->', $content);
            $method = rtrim($method, '()');
            $val = $config[$key] ?? '';
            if ($method === 'strtolower') return strtolower((string)$val);
            if ($method === 'strtoupper') return strtoupper((string)$val);
            if ($method === 'formatImprint') return nl2br((string)$val);
            if ($method === 'formatAccessibility') {
                $lines = GeneralUtility::trimExplode("\n", (string)$val);
                if (!empty($lines)) $lines[0] = sprintf("<strong>%s</strong>", $lines[0]);
                return implode('<br>', $lines);
            }
            return $val;
        }
        return $config[$content] ?? '';
    }

    private function resolveMixedString(string $property, string $value, array $processedRow, array $config, string $tableName): mixed {
        preg_match_all('/{([^{]*?)}/', $value, $matches);
        $hasChanges = false;
        if (!empty($matches[0])) {
            foreach ($matches[0] as $match) {
                if ($match === $value) {
                    return $value;
                }

                $replacement = $this->process($property, $match, $processedRow, $config, $tableName);

                if ($replacement !== $match) {
                    $value = str_replace($match, (string)$replacement, $value);
                    $hasChanges = true;
                }
            }
        }

        if ($hasChanges) {
            return $this->process($property, $value, $processedRow, $config, $tableName);
        }

        return $value;
    }

    private function resolveSorting(string $value, array $processedRow, string $tableName, array $config): int
    {
        $mode = substr($value, 9);
        $pid = $processedRow['pid'] ?? 0;

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable($tableName);

            if ($mode === 'next') {
                $max = $qb->selectLiteral('MAX(sorting)')
                    ->from($tableName)
                    ->where(
                        $qb->expr()->eq('pid', $qb->createNamedParameter($pid)),
                        $qb->expr()->eq('deleted', 0)
                    )
                    ->executeQuery()
                    ->fetchOne();
                return (int)$max + self::SORTING_STEPS;
            }

            if ($mode === 'navname') {
                $currentTitle = $processedRow['title'] ?? $config['navName'] ?? '';
                $currentTitle = strtolower($currentTitle);

                $existingPages = $qb->select('uid', 'title', 'sorting')
                    ->from($tableName)
                    ->where(
                        $qb->expr()->eq('pid', $qb->createNamedParameter($pid)),
                        $qb->expr()->eq('deleted', 0)
                    )
                    ->orderBy('sorting', 'ASC')
                    ->executeQuery()
                    ->fetchAllAssociative();

                if (empty($existingPages)) {
                    return self::SORTING_STEPS;
                }

                $firstPage = array_shift($existingPages);
                $firstPageSorting = (int)$firstPage['sorting'];

                $sortList = [];
                foreach ($existingPages as $page) {
                    $sortList[strtolower($page['title'])] = (int)$page['sorting'];
                }

                $sortList[$currentTitle] = 0;
                ksort($sortList);

                $keys = array_keys($sortList);
                $myIndex = array_search($currentTitle, $keys);

                if ($myIndex === 0) {
                    $nextSorting = isset($keys[1]) ? $sortList[$keys[1]] : ($firstPageSorting + (self::SORTING_STEPS * 2));
                    return (int)ceil($firstPageSorting + ($nextSorting - $firstPageSorting) / 2);
                } else {
                    $prevKey = $keys[$myIndex - 1];
                    $prevSorting = $sortList[$prevKey];

                    $nextKey = $keys[$myIndex + 1] ?? null;
                    if ($nextKey) {
                        $nextSorting = $sortList[$nextKey];
                        return (int)ceil($prevSorting + ($nextSorting - $prevSorting) / 2);
                    } else {
                        return $prevSorting + self::SORTING_STEPS;
                    }
                }
            }

        } catch (\Exception $e) {}

        return self::SORTING_STEPS;
    }
}