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
        // 1. Input bereinigen (z.B. "ed/cce" oder "ls/lss")
        $pathInput = trim((string)$config->department);
        $pathInput = trim($pathInput, "/ \t\n\r\0\x0B");

        if (empty($pathInput)) {
            throw new \RuntimeException("Bitte geben Sie im Feld 'Department' den Zielpfad an (z.B. 'ls/lss').");
        }

        // 2. Pfad auflösen oder fehlende Ebenen erstellen
        // Gibt die UID und den Slug des Zielordners zurück (z.B. von "cce")
        [$targetPid, $targetParentSlug] = $this->resolveOrCreatePath($pathInput);

        // 3. Konfiguration für das neue Projekt (Archiv) vorbereiten
        $projInput = trim((string)$config->navName);
        $shortTitle = $projInput;

        // NavName für Ordnerstruktur generieren
        $cleanPath = str_replace('/', '-', strtolower($pathInput));
        $cleanProj = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $projInput));
        $longNavName = $cleanPath . '-' . $cleanProj;

        // Neuen Slug für das Projekt generieren: Parent-Slug + Projekt-Slug
        // z.B. /ed/cce + /mein-projekt -> /ed/cce/mein-projekt
        $projSlugPart = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $projInput));
        $fullSlug = rtrim($targetParentSlug, '/') . '/' . $projSlugPart;

        $centralArchivWid = 'w00archiv';
        // Upload Pfad Definition
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
        // Nach dem Import suchen wir die neu angelegte Root-Seite des Archivs anhand des neuen Slugs
        $rootPageId = $this->findPageIdBySlug($config->slugName);

        if ($rootPageId > 0) {
            $tsConfig = $this->generateStandardTsConfig($config, $rootPageId);
            $this->updateTsConfig($rootPageId, $tsConfig);
            $this->injectSetup1Constants($rootPageId);
            $this->createSiteConfiguration($config, $rootPageId);
        }
    }

    // --- Core Logic: Path Resolution ---

    /**
     * Löst den Pfad auf. Wenn Teile fehlen, werden sie erstellt.
     * Beispiel Input: "ed/cce"
     * 1. Sucht "/ed/cce" -> nicht gefunden
     * 2. Sucht "/ed" -> gefunden!
     * 3. Erstellt "cce" unter "ed".
     * Rückgabe: [UID von cce, "/ed/cce"]
     */
    private function resolveOrCreatePath(string $pathInput): array
    {
        // Pfad in Segmente zerlegen (z.B. ['ed', 'cce'])
        $segments = explode('/', $pathInput);

        $foundAnchor = null;
        $missingSegments = [];
        $searchSegments = $segments;

        // 1. Suche nach dem tiefsten existierenden Punkt (Anchor)
        while (!empty($searchSegments)) {
            // Slug bauen: z.B. /ed/cce, dann /ed
            $currentSearchSlug = '/' . implode('/', $searchSegments);

            $page = $this->findPageRowBySlug($currentSearchSlug);

            if ($page) {
                // Gefunden! Das ist unser Startpunkt.
                $foundAnchor = $page;
                break;
            }

            // Nicht gefunden: Das letzte Segment ist "missing".
            // Wir nehmen es vom Such-Array weg und packen es an den Anfang der Missing-Liste.
            array_unshift($missingSegments, array_pop($searchSegments));
        }

        if (!$foundAnchor) {
            // Wenn selbst das erste Segment (z.B. "ed") nicht existiert, brechen wir ab.
            // Es muss zumindest einen validen Einstiegspunkt in der DB geben.
            throw new \RuntimeException(sprintf(
                "Konnte keinen existierenden Einstiegspunkt für den Pfad '%s' finden. Bitte stellen Sie sicher, dass zumindest die Basis-Ebene (z.B. '/%s') existiert.",
                $pathInput,
                $segments[0] ?? '?'
            ));
        }

        // 2. Fehlende Segmente erstellen
        $currentPid = (int)$foundAnchor['uid'];
        $currentSlug = (string)$foundAnchor['slug'];

        foreach ($missingSegments as $segmentName) {
            // Segment anlegen (z.B. "cce" unter "ed")
            $newPage = $this->createStandardPage($currentPid, $segmentName, $currentSlug);

            // Update current für den nächsten Durchlauf
            $currentPid = (int)$newPage['uid'];
            $currentSlug = (string)$newPage['slug'];
        }

        return [$currentPid, $currentSlug];
    }

    /**
     * Erstellt eine Standard-Seite (Ordner/Page) in der Datenbank
     */
    private function createStandardPage(int $pid, string $title, string $parentSlug): array
    {
        // Slug generieren: Parent + Title-Slug
        $slugSuffix = '/' . strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $title));
        $newSlug = rtrim($parentSlug, '/') . $slugSuffix;

        // Kollisions-Check (sehr unwahrscheinlich in diesem Flow, aber sicher ist sicher)
        if ($this->findPageRowBySlug($newSlug)) {
            $newSlug .= '-' . substr(md5((string)time()), 0, 4);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->insert('pages')
            ->values([
                'pid' => $pid,
                'title' => $title,
                'doktype' => 1, // Standard Seite
                'slug' => $newSlug,
                'crdate' => time(),
                'tstamp' => time(),
                'perms_userid' => 1,
                'perms_group' => 1,
                'hidden' => 0 // Sofort sichtbar
            ])
            ->executeStatement();

        $newUid = (int)$this->connectionPool->getConnectionForTable('pages')->lastInsertId();

        return ['uid' => $newUid, 'slug' => $newSlug, 'title' => $title];
    }

    /**
     * Sucht eine Seite (UID und Slug) anhand des Slugs.
     */
    private function findPageRowBySlug(string $slug): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');

        $row = $qb->select('uid', 'slug')
            ->from('pages')
            ->where(
                $qb->expr()->eq('slug', $qb->createNamedParameter($slug)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('sys_language_uid', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    // --- Helper & Boilerplate ---

    private function findPageIdBySlug(string $slug): int
    {
        $row = $this->findPageRowBySlug($slug);
        return $row ? (int)$row['uid'] : 0;
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