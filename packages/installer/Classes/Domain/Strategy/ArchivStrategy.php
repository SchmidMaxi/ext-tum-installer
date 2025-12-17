<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Strategy;

use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
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
        $pathInput = trim((string)$config->department);
        $pathInput = trim($pathInput, "/ \t\n\r\0\x0B");

        if (empty($pathInput)) {
            throw new \RuntimeException("Bitte geben Sie im Feld 'Department' den Zielpfad an (z.B. 'ls/lss').");
        }

        // HIER: Exakte Hierarchie-Suche: W00 -> Domain -> Kürzel -> Input-Pfad (Slug-basiert)
        [$targetPid, $targetParentSlug] = $this->resolvePathInKuerzelLevel($pathInput);

        if ($targetPid === 0) {
            throw new \RuntimeException("Konnte den Zielordner in der Struktur 'W00 -> Domain -> Kürzel -> Pfad' nicht finden oder erstellen.");
        }

        $projInput = trim((string)$config->navName);
        $shortTitle = $projInput;

        // NavName generieren
        $cleanPath = str_replace('/', '-', strtolower($pathInput));
        $cleanProj = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $projInput));
        $longNavName = $cleanPath . '-' . $cleanProj;

        // Slug: Parent-Slug (z.B. /domain/ed/ls/lss) + Projekt-Slug
        $projSlugPart = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $projInput));
        $fullSlug = rtrim($targetParentSlug, '/') . '/' . $projSlugPart;

        $centralArchivWid = 'w00archiv';
        $uploadPath = "1:{$centralArchivWid}/{$longNavName}/_my_direct_uploads/";

        return $config->withUpdates([
            'navName'    => $longNavName,
            'siteNameDe' => $shortTitle,
            'slugName'   => $fullSlug,
            'targetPid'  => $targetPid, // Hier wird das Archiv eingehängt
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
        $rootPageId = $this->findPageIdBySlug($config->slugName);

        if ($rootPageId > 0) {
            $tsConfig = $this->generateStandardTsConfig($config, $rootPageId);
            $this->updateTsConfig($rootPageId, $tsConfig);
            $this->injectSetup1Constants($rootPageId);
            $this->createSiteConfiguration($config, $rootPageId);
        }
    }

    private function createSiteConfiguration(InstallationConfig $config, int $rootPageId): void
    {
        $siteWriter = GeneralUtility::makeInstance(SiteWriter::class);
        $identifier = $config->navName;

        $base = $config->slugName;
        if (!empty($config->domain)) {
            $base = rtrim($config->domain, '/') . $base;
        }

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

        try {
            $siteWriter->write($identifier, $newConfig);
        } catch (\Exception $e) {
            // Logging
        }
    }

    // --- Core Logic: Setup1 Path Walker ---

    /**
     * Findet die Hierarchie: W00 -> Domain Root -> [Kürzel] -> [Input Path]
     */
    private function resolvePathInKuerzelLevel(string $path): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');

        // 1. Suche 'W00' (oder 'w00') auf Root-Ebene (PID 0)
        // FIX: 'or()' statt 'orX()' verwenden
        $w00Rows = $qb->select('uid', 'title')
            ->from('pages')
            ->where(
                $qb->expr()->eq('pid', 0),
                $qb->expr()->or(
                    $qb->expr()->eq('title', $qb->createNamedParameter('W00')),
                    $qb->expr()->eq('title', $qb->createNamedParameter('w00')),
                    $qb->expr()->like('title', $qb->createNamedParameter('W00%')),
                    $qb->expr()->like('title', $qb->createNamedParameter('w00%'))
                ),
                $qb->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $w00Uid = 0;

        if (!empty($w00Rows)) {
            $w00Uid = (int)$w00Rows[0]['uid'];
        }

        if ($w00Uid === 0) {
            throw new \RuntimeException("Fehler: Kein Ordner 'W00' oder 'w00' auf Root-Ebene gefunden.");
        }

        // 2. Suche die Domain-Ebene (Site Root) INNERHALB von W00
        $domainRoot = $qb->select('uid', 'slug')
            ->from('pages')
            ->where(
                $qb->expr()->eq('pid', $w00Uid),
                $qb->expr()->eq('is_siteroot', 1),
                $qb->expr()->eq('deleted', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$domainRoot) {
            throw new \RuntimeException("Fehler: Keine Domain-Seite (Site Root) innerhalb von 'W00' (UID: $w00Uid) gefunden.");
        }

        // 3. Suche die KÜRZEL-EBENE (Das erste Kind der Domain Root)
        // Wir nehmen das erste verfügbare Element (Seite/Ordner/Shortcut)
        $kuerzelRoot = $qb->select('uid', 'slug')
            ->from('pages')
            ->where(
                $qb->expr()->eq('pid', $domainRoot['uid']),
                $qb->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting', 'ASC') // Den ersten in der Liste nehmen
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$kuerzelRoot) {
            throw new \RuntimeException("Fehler: Es wurde kein Unterordner (Kürzel-Ebene) unterhalb der Domain-Root (UID: {$domainRoot['uid']}) gefunden.");
        }

        // STARTPUNKT: Innerhalb des Kürzel-Ordners
        $currentPid = (int)$kuerzelRoot['uid'];
        $currentSlug = (string)$kuerzelRoot['slug'];

        // 4. Input Pfad (z.B. "ls/lss") ablaufen und fehlende Ordner erstellen
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (empty($segment)) continue;

            // Suche primär nach SLUG Segment
            $child = $this->findChildInPid($currentPid, $segment);

            if (!$child) {
                // Nicht gefunden -> Anlegen
                $child = $this->createStandardPage($currentPid, $segment, $currentSlug);
            }

            // Abstieg in die nächste Ebene
            $currentPid = (int)$child['uid'];
            $currentSlug = (string)$child['slug'];
        }

        // Rückgabe: ID des letzten Ordners (z.B. 'lss')
        return [$currentPid, $currentSlug];
    }

    private function findChildInPid(int $pid, string $segment): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');

        // Wir laden alle Kinder und prüfen PHP-seitig, um komplexe SQL-Operationen zu vermeiden
        $children = $qb->select('uid', 'title', 'slug')->from('pages')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid)),
                $qb->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $searchSlugPart = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $segment));
        $searchTitle = strtolower($segment);

        foreach ($children as $child) {
            $slug = strtolower((string)($child['slug'] ?? ''));

            // Priorität 1: Slug Match (Endet auf /segment)
            if (str_ends_with($slug, '/' . $searchSlugPart)) {
                return $child;
            }
        }

        // Priorität 2: Fallback auf Titel (falls Slug noch nicht generiert oder abweichend)
        foreach ($children as $child) {
            if (strtolower((string)($child['title'] ?? '')) === $searchTitle) {
                return $child;
            }
        }

        return null;
    }

    private function createStandardPage(int $pid, string $title, string $parentSlug): array
    {
        $slugSuffix = '/' . strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $title));
        $newSlug = rtrim($parentSlug, '/') . $slugSuffix;

        // Collision Check
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
                'hidden' => 0 // Sichtbar!
            ])
            ->executeStatement();

        $newUid = (int)$this->connectionPool->getConnectionForTable('pages')->lastInsertId();
        return ['uid' => $newUid, 'slug' => $newSlug, 'title' => $title];
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