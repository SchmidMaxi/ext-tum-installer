<?php
declare(strict_types=1);

namespace Tum\Installer\Domain\Model;

readonly class InstallationConfig
{
    public function __construct(
        public SetupType $type,
        public string $navName,
        public string $domain,
        public string $wid,
        // Optional Inputs
        public string $parentOu = '',
        public string $department = '',
        public string $siteNameDe = '',
        public string $siteNameEn = '',
        public string $parentOuNameDe = '',
        public string $parentOuNameEn = '',
        public string $parentOuUrlDe = '',
        public string $parentOuUrlEn = '',
        public string $imprint = '',
        public string $accessibility = '',

        // Features (Booleans)
        public bool $hasNews = false,
        public bool $hasIntropage = false,
        public bool $hasCurlContent = false,
        public bool $hasMemberList = false,
        public bool $hasCourses = false,
        public bool $hasVcard = false,

        // Runtime Values (werden von Strategie berechnet)
        public ?int $targetPid = null,
        public ?string $uploadPath = null,
        public ?string $slugName = null
    ) {}

    public function withUpdates(array $updates): self
    {
        $args = get_object_vars($this);
        foreach ($updates as $key => $value) {
            $args[$key] = $value;
        }
        return new self(...$args);
    }

    public function toArray(): array
    {
        // Mapping für YAML Conditions (z.B. _condition: news -> schaut auf hasNews)
        $arr = get_object_vars($this);
        $arr['news'] = $this->hasNews;
        $arr['intropage'] = $this->hasIntropage;
        $arr['curlContent'] = $this->hasCurlContent;
        $arr['memberList'] = $this->hasMemberList;
        $arr['courses'] = $this->hasCourses;
        $arr['vcard'] = $this->hasVcard;
        return $arr;
    }
}