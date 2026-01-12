<?php

namespace ElementareTeilchen\Sitetum\Command;

use ElementareTeilchen\Sitetum\Services\SiteSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(
    name: 'yamlManager:copyErrorHandling',
    description: 'Copy 404 error handling to create 403 error handling with the same errorContentSource'
)]
class YamlManagerCopyErrorHandlingCommand extends Command
{
    protected SymfonyStyle $io;

    public function __construct(
        private readonly SiteFinder $siteFinder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Copies the errorHandling 404 configuration to create a 403 configuration with the same errorContentSource.\n\n"
            . "This command will:\n"
            . "1. Read all site config.yaml files\n"
            . "2. For each site, find the errorHandling 404 configuration\n"
            . '3. Create a new entry for 403 with the same errorContentSource'
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->siteFinder->getAllSites(false) as $site) {
            $siteIdentifier = $site->getIdentifier();
            $configFilePath = Environment::getConfigPath() . '/sites/' . $siteIdentifier . '/config.yaml';

            if (!file_exists($configFilePath)) {
                $this->io->warning('Config file not found for site: ' . $siteIdentifier);
                continue;
            }

            $this->io->writeln('Processing site: ' . $siteIdentifier);

            try {
                $yamlContent = Yaml::parseFile($configFilePath);

                if (!isset($yamlContent['errorHandling'])) {
                    $this->io->warning('No errorHandling section found in site: ' . $siteIdentifier);
                    continue;
                }

                $error404Config = null;
                $has403Config = false;

                foreach ($yamlContent['errorHandling'] as $errorConfig) {
                    if (isset($errorConfig['errorCode']) && (int)$errorConfig['errorCode'] === 404) {
                        $error404Config = $errorConfig;
                    }
                    if (isset($errorConfig['errorCode']) && (int)$errorConfig['errorCode'] === 403) {
                        $has403Config = true;
                    }
                }

                if ($error404Config === null) {
                    $this->io->warning('No 404 error handling configuration found in site: ' . $siteIdentifier);
                    continue;
                }

                if ($has403Config) {
                    $this->io->writeln('403 error handling configuration already exists in site: ' . $siteIdentifier);
                    continue;
                }

                $settingsService = new SiteSettingsService($site, 'config');

                $nextIndex = count($yamlContent['errorHandling']);

                $settingsService->modifySetting(
                    'errorHandling.' . $nextIndex . '.errorCode',
                    '403'
                );

                $settingsService->modifySetting(
                    'errorHandling.' . $nextIndex . '.errorHandler',
                    (string)$error404Config['errorHandler']
                );

                $settingsService->modifySetting(
                    'errorHandling.' . $nextIndex . '.errorContentSource',
                    (string)$error404Config['errorContentSource']
                );

                $this->io->success('Added 403 error handling configuration to site: ' . $siteIdentifier);
            } catch (\Exception $e) {
                $this->io->error('Error processing site ' . $siteIdentifier . ': ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
