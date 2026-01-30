<?php

declare(strict_types=1);

namespace Tum\Installer\Domain\Model;

readonly class WebinfoData
{
    public function __construct(
        public string $umgebung,
        public string $organizationUnit,
        public string $websiteType,
        public string $typo3Version,
        public ?\DateTime $laufzeitBis,
        public string $nachLaufzeitende,
        public string $notiz = ''
    ) {}

    public function toArray(): array
    {
        return [
            'umgebung' => $this->umgebung,
            'organization_unit' => $this->organizationUnit,
            'website_type' => $this->websiteType,
            'typo3_version' => $this->typo3Version,
            'laufzeit_bis' => $this->laufzeitBis?->format('Y-m-d'),
            'nach_laufzeitende' => $this->nachLaufzeitende,
            'notiz' => $this->notiz,
        ];
    }
}
