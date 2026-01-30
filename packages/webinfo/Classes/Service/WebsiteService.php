<?php

declare(strict_types=1);

namespace Tum\Webinfo\Service;

use Tum\Webinfo\Domain\Model\Website;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class WebsiteService
{
    private const TABLE = 'tx_webinfo_domain_model_website';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    public function createFromArray(array $data): Website
    {
        $website = new Website();
        $website->setUrl($data['url'] ?? '');
        $website->setDomain($data['domain'] ?? '');
        $website->setNavName($data['nav_name'] ?? '');
        $website->setWid($data['wid'] ?? '');
        $website->setSetup($data['setup'] ?? '');
        $website->setUmgebung($data['umgebung'] ?? '');
        $website->setOrganizationUnit($data['organization_unit'] ?? '');
        $website->setWebsiteType($data['website_type'] ?? '');
        $website->setTypo3Version($data['typo3_version'] ?? '');
        $website->setAfterExpiry($data['nach_laufzeitende'] ?? $data['after_expiry'] ?? '');
        $website->setNote($data['notiz'] ?? $data['note'] ?? '');

        // Parse dates
        if (!empty($data['created_at'])) {
            $website->setCreatedAt(new \DateTime($data['created_at']));
        } else {
            $website->setCreatedAt(new \DateTime());
        }

        if (!empty($data['laufzeit_bis']) || !empty($data['valid_until'])) {
            $dateStr = $data['laufzeit_bis'] ?? $data['valid_until'];
            $website->setValidUntil(new \DateTime($dateStr));
        }

        // Check if website with same URL already exists
        $existing = $this->findByUrl($website->getUrl());
        if ($existing !== null) {
            // Update existing record
            return $this->update($existing->getUid(), $website);
        }

        // Insert new record
        return $this->insert($website);
    }

    private function insert(Website $website): Website
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $data = [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'url' => $website->getUrl(),
            'domain' => $website->getDomain(),
            'nav_name' => $website->getNavName(),
            'wid' => $website->getWid(),
            'setup' => $website->getSetup(),
            'umgebung' => $website->getUmgebung(),
            'organization_unit' => $website->getOrganizationUnit(),
            'website_type' => $website->getWebsiteType(),
            'typo3_version' => $website->getTypo3Version(),
            'created_at' => $website->getCreatedAt()?->getTimestamp() ?? 0,
            'valid_until' => $website->getValidUntil()?->getTimestamp() ?? 0,
            'after_expiry' => $website->getAfterExpiry(),
            'note' => $website->getNote(),
        ];

        $connection->insert(self::TABLE, $data);
        $uid = (int)$connection->lastInsertId();

        // Create a new Website object with the UID
        $result = clone $website;
        $reflection = new \ReflectionProperty(Website::class, 'uid');
        $reflection->setAccessible(true);
        $reflection->setValue($result, $uid);

        return $result;
    }

    private function update(int $uid, Website $website): Website
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $data = [
            'tstamp' => time(),
            'domain' => $website->getDomain(),
            'nav_name' => $website->getNavName(),
            'wid' => $website->getWid(),
            'setup' => $website->getSetup(),
            'umgebung' => $website->getUmgebung(),
            'organization_unit' => $website->getOrganizationUnit(),
            'website_type' => $website->getWebsiteType(),
            'typo3_version' => $website->getTypo3Version(),
            'created_at' => $website->getCreatedAt()?->getTimestamp() ?? 0,
            'valid_until' => $website->getValidUntil()?->getTimestamp() ?? 0,
            'after_expiry' => $website->getAfterExpiry(),
            'note' => $website->getNote(),
        ];

        $connection->update(self::TABLE, $data, ['uid' => $uid]);

        $reflection = new \ReflectionProperty(Website::class, 'uid');
        $reflection->setAccessible(true);
        $reflection->setValue($website, $uid);

        return $website;
    }

    public function findByUrl(string $url): ?Website
    {
        $queryBuilder = $this->getQueryBuilder();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($url)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToWebsite($row);
    }

    public function findByUid(int $uid): ?Website
    {
        $queryBuilder = $this->getQueryBuilder();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $uid),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToWebsite($row);
    }

    /**
     * @return Website[]
     */
    public function findAll(string $searchTerm = '', int $limit = 100, int $offset = 0): array
    {
        $queryBuilder = $this->getQueryBuilder();

        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!empty($searchTerm)) {
            $this->addSearchConditions($queryBuilder, $searchTerm);
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map([$this, 'mapRowToWebsite'], $rows);
    }

    public function countAll(string $searchTerm = ''): int
    {
        $queryBuilder = $this->getQueryBuilder();

        $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0));

        if (!empty($searchTerm)) {
            $this->addSearchConditions($queryBuilder, $searchTerm);
        }

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    private function addSearchConditions(QueryBuilder $queryBuilder, string $searchTerm): void
    {
        $searchFields = [
            'url', 'domain', 'nav_name', 'wid', 'setup',
            'umgebung', 'organization_unit', 'website_type',
            'typo3_version', 'after_expiry', 'note'
        ];

        $orConditions = [];
        foreach ($searchFields as $field) {
            $orConditions[] = $queryBuilder->expr()->like(
                $field,
                $queryBuilder->createNamedParameter('%' . $searchTerm . '%')
            );
        }

        $queryBuilder->andWhere($queryBuilder->expr()->or(...$orConditions));
    }

    private function mapRowToWebsite(array $row): Website
    {
        $website = new Website();

        $reflection = new \ReflectionProperty(Website::class, 'uid');
        $reflection->setAccessible(true);
        $reflection->setValue($website, (int)$row['uid']);

        $website->setUrl($row['url'] ?? '');
        $website->setDomain($row['domain'] ?? '');
        $website->setNavName($row['nav_name'] ?? '');
        $website->setWid($row['wid'] ?? '');
        $website->setSetup($row['setup'] ?? '');
        $website->setUmgebung($row['umgebung'] ?? '');
        $website->setOrganizationUnit($row['organization_unit'] ?? '');
        $website->setWebsiteType($row['website_type'] ?? '');
        $website->setTypo3Version($row['typo3_version'] ?? '');
        $website->setAfterExpiry($row['after_expiry'] ?? '');
        $website->setNote($row['note'] ?? '');

        if (!empty($row['created_at'])) {
            $website->setCreatedAt((new \DateTime())->setTimestamp((int)$row['created_at']));
        }
        if (!empty($row['valid_until'])) {
            $website->setValidUntil((new \DateTime())->setTimestamp((int)$row['valid_until']));
        }

        return $website;
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE);
    }
}
