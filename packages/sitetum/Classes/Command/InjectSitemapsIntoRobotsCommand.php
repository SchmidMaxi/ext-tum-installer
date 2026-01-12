<?php

declare(strict_types=1);

namespace ElementareTeilchen\Sitetum\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(
    name: 'sitetum:robots:inject-sitemaps',
    description: 'Injects sitemap URLs for all sites and languages into robots.txt'
)]
class InjectSitemapsIntoRobotsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate output without writing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force update even if no existing sitemap block')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Custom path to robots.txt (defaults to public/robots.txt)',
                Environment::getPublicPath() . '/robots.txt'
            )
            ->addOption(
                'include-domain-level',
                null,
                InputOption::VALUE_NONE,
                'Include sites whose identifier ends with -d (default: false)'
            )
            ->addOption(
                'include-all-languages',
                null,
                InputOption::VALUE_NONE,
                'Include all languages in sitemap output (default: false = only default language)'
            );
    }

    public function __construct(
        protected readonly SiteFinder $siteFinder
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $force = (bool)$input->getOption('force');
        $includeDomainLevel = (bool)$input->getOption('include-domain-level');
        $includeAllLanguages = (bool)$input->getOption('include-all-languages');
        $robotsTxtPath = (string)$input->getOption('path');

        if (!file_exists($robotsTxtPath)) {
            $output->writeln('<error>robots.txt not found at: ' . $robotsTxtPath . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Injecting sitemap URLs into: ' . $robotsTxtPath . '</info>');

        $robotsContent = file_get_contents($robotsTxtPath);
        $hasSitemapBlock = str_contains($robotsContent, '###Sitemaps-Start###');
        $robotsContent = $this->stripOldSitemaps($robotsContent);

        $sitemapEntries = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            $siteIdentifier = $site->getIdentifier();

            // Skip domain-level sites unless explicitly included
            if (!$includeDomainLevel && str_ends_with($siteIdentifier, '-d')) {
                $output->writeln("<comment>Skipping domain-level site: $siteIdentifier</comment>");
                continue;
            }

            if ($includeAllLanguages) {
                foreach ($site->getAllLanguages() as $language) {
                    $languageBaseUrl = rtrim((string)$language->getBase(), '/');
                    $sitemapEntries[] = 'Sitemap: ' . $languageBaseUrl . '/sitemap.xml';
                }
            } else {
                $defaultLanguage = $site->getDefaultLanguage();
                $defaultBaseUrl = rtrim((string)$defaultLanguage->getBase(), '/');
                $sitemapEntries[] = 'Sitemap: ' . $defaultBaseUrl . '/sitemap.xml';
            }
        }

        if (empty($sitemapEntries)) {
            $output->writeln('<comment>No sitemap URLs generated.</comment>');
            return Command::SUCCESS;
        }

        $newBlock = PHP_EOL
            . '###Sitemaps-Start###' . PHP_EOL
            . implode(PHP_EOL, $sitemapEntries) . PHP_EOL
            . '###Sitemaps-End###' . PHP_EOL;

        if ($dryRun) {
            $output->writeln('<comment>[Dry Run] robots.txt would be updated with:</comment>');
            $output->writeln($newBlock);
            return Command::SUCCESS;
        }

        if (!$hasSitemapBlock && !$force) {
            $output->writeln('<comment>No sitemap block found. Use --force to add block.</comment>');
            return Command::SUCCESS;
        }

        file_put_contents($robotsTxtPath, $robotsContent . $newBlock);
        $output->writeln('<info>robots.txt updated with ' . count($sitemapEntries) . ' sitemap entries.</info>');

        return Command::SUCCESS;
    }

    protected function stripOldSitemaps(string $content): string
    {
        $pattern = '/###Sitemaps-Start###.*?###Sitemaps-End###/s';
        return trim(preg_replace($pattern, '', $content)) . PHP_EOL;
    }
}
