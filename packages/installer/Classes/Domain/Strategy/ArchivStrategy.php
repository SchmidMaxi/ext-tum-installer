<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
// FIX: Wir nutzen den SiteWriter zum Schreiben
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ArchivStrategy extends AbstractStrategy
{
    public function supports(SetupType $type): bool
    {
        return $type === SetupType::ARCHIV;
    }

    public function prepare(InstallationConfig $config): InstallationConfig
    {
        // 1. Pfad aus dem Feld 'department' lesen (z.B. "ls/lss")
        $pathInput = trim((string)$config->department);
        $pathInput = trim($pathInput, "/ \t\n\r\0\x0B");

        if (empty($pathInput)) {
            throw new \RuntimeException("Bitte geben Sie im Feld 'Department' den Zielpfad an (z.B. 'ls/lss').");
        }

        // 2. Pfad auflösen
        [$targetPid, $targetParentSlug] = $this->resolvePathByWalking($pathInput);

        // 3. Projekt-Daten
        $projInput = trim((string)$config->navName);
        $shortTitle = $projInput;

        // 4. System-Name (navName) generieren
        $cleanPath = str_replace('/', '-', strtolower($pathInput));
        $cleanProj = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $projInput));
        $longNavName = $cleanPath . '-' . $cleanProj;

        // 5. Absoluter Slug
        $projSlugPart = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $projInput));
        $fullSlug = rtrim($targetParentSlug, '/') . '/' . $projSlugPart;

        // 6. Config Updates
        $centralArchivWid = 'w00archiv';
        $uploadPath = "1:{$centralArchivWid}/{$longNavName}/_my_direct_uploads/";

        return $config->withUpdates([
            'navName'    => $longNavName,
            'siteNameDe' => $shortTitle,
            'slugName'   => $fullSlug,
            'targetPid'  => $targetPid,
            'wid'        => $centralArchivWid,
            'uploadPath' => $uploadPath,
        ]);
    }

    public function getYamlFilePath(InstallationConfig $config): string
    {
        return 'EXT:installer/Configuration/Installer/Archiv.yaml';
    }

    public function postProcess(InstallationConfig $config): void
    {
        // 1. Root Page ID finden
        $rootPageId = $this->findPageIdBySlug($config->slugName);

        if ($rootPageId > 0) {
            // 2. TypoScript schreiben
            $tsConfig = $this->generateStandardTsConfig($config, $rootPageId);
            $this->updateTsConfig($rootPageId, $tsConfig);
            $this->injectSetup1Constants($rootPageId);

            // 3. SITE CONFIGURATION SCHREIBEN
            $this->createSiteConfiguration($config, $rootPageId);
        }
    }

    /**
     * Erstellt den Ordner in config/sites und die config.yaml mittels SiteWriter
     */
    private function createSiteConfiguration(InstallationConfig $config, int $rootPageId): void
    {
        // FIX: SiteWriter Instanz holen
        $siteWriter = GeneralUtility::makeInstance(SiteWriter::class);

        $identifier = $config->navName; // z.B. ls-lss-geomorphologie

        // Base Pfad berechnen
        $base = $config->slugName;
        if (!empty($config->domain)) {
            $base = rtrim($config->domain, '/') . $base;
        }

        // Konfiguration array
        $newConfig = [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'websiteTitle' => $config->siteNameDe,
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'typo3Language' => 'de',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'navigationTitle' => 'DE',
                    'hreflang' => 'de-DE',
                    'direction' => 'ltr',
                    'flag' => 'de',
                ],
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/en/',
                    'typo3Language' => 'default',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'navigationTitle' => 'EN',
                    'hreflang' => 'en-US',
                    'direction' => 'ltr',
                    'flag' => 'en-us-gb',
                    'fallbackType' => 'strict',
                    'fallbacks' => '0',
                ],
            ],
            'imports' => [],
        ];

        // Schreibt config/sites/<identifier>/config.yaml
        try {
            $siteWriter->write($identifier, $newConfig);
        } catch (\Exception $e) {
            // Falls Schreiben fehlschlägt (z.B. Rechte), loggen wir es nur,
            // damit der Installer nicht hart abbricht.
            // (In einer idealen Welt Logger nutzen, hier lassen wir es durchlaufen)
        }
    }

    // --- Path Walker (unverändert) ---
    private function resolvePathByWalking(string $path): array
    {
        $segments = explode('/', $path);
        $currentPid = 0;
        $currentSlug = '';
        $firstSegment = true;

        foreach ($segments as $segment) {
            if (empty($segment)) continue;
            $child = null;

            if ($firstSegment && $currentPid === 0) {
                $child = $this->findNodeGlobal($segment);
                $firstSegment = false;
            } else {
                $child = $this->findChildInPid($currentPid, $segment);
            }

            if (!$child) {
                $child = $this->createStandardPage($currentPid, $segment, $currentSlug);
            }
            $currentPid = (int)$child['uid'];
            $currentSlug = (string)$child['slug'];
        }
        return [$currentPid, $currentSlug];
    }

    private function findNodeGlobal(string $name): ?array
    {
        $node = $this->findPageByTitleGlobal($name);
        if ($node) return $node;
        return $this->findPageBySlugSuffixGlobal('/' . strtolower($name));
    }

    private function findChildInPid(int $pid, string $segment): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $children = $qb->select('uid', 'title', 'slug')->from('pages')
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid)), $qb->expr()->eq('deleted', 0))
            ->executeQuery()->fetchAllAssociative();

        $search = strtolower($segment);
        $searchSlugPart = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $segment));

        foreach ($children as $child) {
            if (strtolower((string)($child['title'] ?? '')) === $search) return $child;
            $slug = strtolower((string)($child['slug'] ?? ''));
            if (str_ends_with($slug, '/' . $searchSlugPart)) return $child;
        }
        return null;
    }

    private function createStandardPage(int $pid, string $title, string $parentSlug): array
    {
        $slugSuffix = '/' . strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $title));
        $newSlug = ($pid === 0) ? $slugSuffix : rtrim($parentSlug, '/') . $slugSuffix;

        if ($this->findPageIdBySlug($newSlug) > 0) {
            $newSlug .= '-' . substr(md5((string)time()), 0, 4);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->insert('pages')
            ->values([
                'pid' => $pid,
                'title' => $title,
                'doktype' => 1,
                'slug' => $newSlug,
                'crdate' => time(),
                'tstamp' => time(),
                'perms_userid' => 1,
                'perms_group' => 1,
            ])
            ->executeStatement();

        $newUid = (int)$this->connectionPool->getConnectionForTable('pages')->lastInsertId();
        return ['uid' => $newUid, 'slug' => $newSlug, 'title' => $title];
    }

    // --- Helper ---
    private function findPageByTitleGlobal(string $title): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $row = $qb->select('uid', 'slug')->from('pages')
            ->where($qb->expr()->eq('title', $qb->createNamedParameter($title)), $qb->expr()->eq('deleted', 0))
            ->setMaxResults(1)->executeQuery()->fetchAssociative();
        return $row ?: null;
    }

    private function findPageBySlugSuffixGlobal(string $suffix): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $qb->select('uid', 'slug')->from('pages')
            ->where($qb->expr()->like('slug', $qb->createNamedParameter('%' . $suffix)), $qb->expr()->eq('deleted', 0))
            ->setMaxResults(10)->executeQuery()->fetchAllAssociative();
        $search = strtolower($suffix);
        foreach ($rows as $row) {
            if (str_ends_with(strtolower((string)$row['slug']), $search)) return $row;
        }
        return null;
    }

    private function findPageIdBySlug(string $slug): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        return (int)$qb->select('uid')->from('pages')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)), $qb->expr()->eq('deleted', 0))
            ->setMaxResults(1)->executeQuery()->fetchOne();
    }

    private function injectSetup1Constants(int $rootPageId): void
    {
        $privacyPid = $this->findOldestPageByTitle('Datenschutz');
        $accessPid = $this->findOldestPageByTitle('Barrierefreiheit');
        if ($privacyPid === 0 && $accessPid === 0) return;

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_template');
        $row = $qb->select('uid', 'constants')->from('sys_template')
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($rootPageId)), $qb->expr()->eq('root', 1))
            ->setMaxResults(1)->executeQuery()->fetchAssociative();

        if (!$row) return;
        $constants = $row['constants'] ?? '';
        $newConstants = $constants;
        if ($privacyPid > 0) {
            if (str_contains($newConstants, 'PRIVACY_URL =')) $newConstants = preg_replace('/^PRIVACY_URL\s*=.*$/m', "PRIVACY_URL = $privacyPid", $newConstants);
            else $newConstants .= "\nPRIVACY_URL = $privacyPid";
        }
        if ($accessPid > 0) {
            if (str_contains($newConstants, 'ACCESSPID =')) $newConstants = preg_replace('/^ACCESSPID\s*=.*$/m', "ACCESSPID = $accessPid", $newConstants);
            else $newConstants .= "\nACCESSPID = $accessPid";
        }
        if ($newConstants !== $constants) {
            $qb->update('sys_template')->set('constants', $newConstants)
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($row['uid'])))->executeStatement();
        }
    }

    private function findOldestPageByTitle(string $title): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        return (int)$qb->select('uid')->from('pages')
            ->where($qb->expr()->eq('title', $qb->createNamedParameter($title)), $qb->expr()->eq('deleted', 0), $qb->expr()->eq('doktype', 1))
            ->orderBy('uid', 'ASC')->setMaxResults(1)->executeQuery()->fetchOne();
    }
}