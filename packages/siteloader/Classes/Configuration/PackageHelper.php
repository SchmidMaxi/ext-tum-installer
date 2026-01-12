<?php

declare(strict_types=1);

namespace ElementareTeilchen\Siteloader\Configuration;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Package\Exception\UnknownPackageException;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Helper to fetch the configured site package
 */
class PackageHelper
{
    /**
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @var SiteFinder
     */
    protected $siteFinder;

    public function __construct(PackageManager $packageManager, SiteFinder $siteFinder)
    {
        $this->packageManager = $packageManager;
        $this->siteFinder = $siteFinder;
    }

    public function getSitePackage(int $rootPageId): ?PackageInterface
    {
        try {
            return $this->getSitePackageFromSite(
                $this->siteFinder->getSiteByRootPageId($rootPageId)
            );
        } catch (SiteNotFoundException $e) {
            return null;
        }
    }

    public function getSitePackageFromSite(Site $site): ?PackageInterface
    {
        $configuration = $site->getConfiguration();
        if (!isset($configuration['sitePackage'])) {
            return null;
        }
        $packageKey = (string)$configuration['sitePackage'];
        try {
            return $this->packageManager->getPackage($packageKey);
        } catch (UnknownPackageException $_) {
            return null;
        }
    }

}
