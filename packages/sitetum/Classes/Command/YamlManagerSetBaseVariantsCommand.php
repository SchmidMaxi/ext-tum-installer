<?php

namespace ElementareTeilchen\Sitetum\Command;

use ElementareTeilchen\Sitetum\Services\SiteSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(
    name: 'yamlManager:setBaseVariants',
    description: 'Make sure generic domains are set as baseVariants'
)]
class YamlManagerSetBaseVariantsCommand extends Command
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
            'Makes sure generic domains are set as baseVariants in all sites.'
        );
        $this->addOption(
            'systemIdentifier',
            null,
            InputOption::VALUE_REQUIRED,
            'The identifier of the current system, ie. lu43tur'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws SiteNotFoundException
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $systemIdentifier = (string)$input->getOption('systemIdentifier');

        if ($systemIdentifier === '') {
            $this->io->error('Option --systemIdentifier is required.');
            return Command::FAILURE;
        }

        foreach ($this->siteFinder->getAllSites(false) as $site) {
            // the dummy base domain site config does not need baseVariants
            if (str_ends_with($site->getIdentifier(), '-d')) {
                continue;
            }

            $this->io->writeln('** set baseVariants for ' . $site->getIdentifier());

            try {
                $settingsService = new SiteSettingsService($site, 'config');

                $baseUrl = 'https://' . $systemIdentifier . '-v12.typo3.lrz.de/' . $site->getIdentifier() . '/';
                $baseUrlEn = 'https://' . $systemIdentifier . '-v12.typo3.lrz.de/en/' . $site->getIdentifier() . '/';
                $condition = 'applicationContext == "Production/Testing"';

                $settingsService->modifySetting('baseVariants.0.base', $baseUrl);

                $settingsService->modifySetting('languages.0.baseVariants.0.base', $baseUrl);

                $settingsService->modifySetting('languages.1.baseVariants.0.base', $baseUrlEn);

                $settingsService->modifySetting('baseVariants.0.condition', $condition);

                $settingsService->modifySetting('languages.0.baseVariants.0.condition', $condition);

                $settingsService->modifySetting('languages.1.baseVariants.0.condition', $condition);

                $this->io->success('Updated baseVariants for ' . $site->getIdentifier());
            } catch (\Exception $e) {
                $this->io->error('failed to write baseVariants for ' . $site->getIdentifier() . ' ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
