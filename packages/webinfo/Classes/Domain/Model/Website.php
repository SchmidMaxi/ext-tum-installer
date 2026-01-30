<?php

declare(strict_types=1);

namespace Tum\Webinfo\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Website extends AbstractEntity
{
    protected string $url = '';
    protected string $domain = '';
    protected string $navName = '';
    protected string $wid = '';
    protected string $setup = '';
    protected string $umgebung = '';
    protected string $organizationUnit = '';
    protected string $websiteType = '';
    protected string $typo3Version = '';
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $validUntil = null;
    protected string $afterExpiry = '';
    protected string $note = '';

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): void
    {
        $this->domain = $domain;
    }

    public function getNavName(): string
    {
        return $this->navName;
    }

    public function setNavName(string $navName): void
    {
        $this->navName = $navName;
    }

    public function getWid(): string
    {
        return $this->wid;
    }

    public function setWid(string $wid): void
    {
        $this->wid = $wid;
    }

    public function getSetup(): string
    {
        return $this->setup;
    }

    public function setSetup(string $setup): void
    {
        $this->setup = $setup;
    }

    public function getUmgebung(): string
    {
        return $this->umgebung;
    }

    public function setUmgebung(string $umgebung): void
    {
        $this->umgebung = $umgebung;
    }

    public function getOrganizationUnit(): string
    {
        return $this->organizationUnit;
    }

    public function setOrganizationUnit(string $organizationUnit): void
    {
        $this->organizationUnit = $organizationUnit;
    }

    public function getWebsiteType(): string
    {
        return $this->websiteType;
    }

    public function setWebsiteType(string $websiteType): void
    {
        $this->websiteType = $websiteType;
    }

    public function getTypo3Version(): string
    {
        return $this->typo3Version;
    }

    public function setTypo3Version(string $typo3Version): void
    {
        $this->typo3Version = $typo3Version;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getValidUntil(): ?\DateTime
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTime $validUntil): void
    {
        $this->validUntil = $validUntil;
    }

    public function getAfterExpiry(): string
    {
        return $this->afterExpiry;
    }

    public function setAfterExpiry(string $afterExpiry): void
    {
        $this->afterExpiry = $afterExpiry;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): void
    {
        $this->note = $note;
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'url' => $this->url,
            'domain' => $this->domain,
            'nav_name' => $this->navName,
            'wid' => $this->wid,
            'setup' => $this->setup,
            'umgebung' => $this->umgebung,
            'organization_unit' => $this->organizationUnit,
            'website_type' => $this->websiteType,
            'typo3_version' => $this->typo3Version,
            'created_at' => $this->createdAt?->format('Y-m-d'),
            'valid_until' => $this->validUntil?->format('Y-m-d'),
            'after_expiry' => $this->afterExpiry,
            'note' => $this->note,
        ];
    }
}
