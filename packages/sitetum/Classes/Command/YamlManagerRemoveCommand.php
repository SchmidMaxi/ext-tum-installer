<?php

namespace ElementareTeilchen\Sitetum\Command;

use ElementareTeilchen\Sitetum\Services\SiteSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(
    name: 'yamlManager:remove',
    description: 'Removes a value from the site config'
)]
class YamlManagerRemoveCommand extends Command
{
    protected string $key;

    protected string $value;

    protected string $file;

    /**
     * @var array<SiteSettingsService>
     */
    protected array $siteSettingsServices = [];

    protected SymfonyStyle $io;

    public function __construct(
        private readonly SiteFinder $siteFinder
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Remove a value from a site config YAML file.\n\n"
            . "Example usage: typo3 yamlManager:remove --site someSite settings page.constants.1.someConstant\n"
            . "This will modify a file '{CONFIG_PATH}/sites/someSite/settings.yaml' in the following way:\n\n"
            . "page:\n"
            . "  somethingElse: someValue\n"
            . "  constants:\n"
            . "    - someOtherValue\n"
            . "    - \n"
            . "      somethingelseConstant: someValue\n"
            . "      someConstant: oldValue (<-- this value will be removed)\n\n"
            . 'WARNING: Be careful when removing complex values! The command WILL remove the entire value, no '
            . "matter what it contains!\n"
            . "Example: typo3 yamlManager:remove --site someSite settings page.constants\n"
            . "That command would do the following to the example above:\n\n"
            . "page:\n"
            . "  somethingElse: someValue\n"
        );

        $this->addArgument(
            'file',
            InputArgument::REQUIRED,
            "The name of the site settings YAML file to modify\n"
            . "(Example: 'settings' for \${CONFIG_PATH}/sites/\${SITE_NAME}/settings.yaml)."
        );
        $this->addArgument(
            'key',
            InputArgument::REQUIRED,
            'The YAML key to remove. List items can be accessed via their numerical index (see examples below)'
        );
        $this->addOption(
            'site',
            's',
            InputOption::VALUE_OPTIONAL,
            'Restrict the changes to a specific site. Defaults to all sites.'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws SiteNotFoundException
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Cast auf string für PHP 8.4 Typsicherheit
        $this->file = (string)$input->getArgument('file');
        $this->key = (string)$input->getArgument('key');
        $this->io = new SymfonyStyle($input, $output);

        $siteIdentifier = $input->getOption('site');

        if ($siteIdentifier) {
            $site = $this->siteFinder->getSiteByIdentifier((string)$siteIdentifier);
            $this->siteSettingsServices[] = new SiteSettingsService($site, $this->file);
        } else {
            foreach ($this->siteFinder->getAllSites(false) as $site) {
                $this->siteSettingsServices[] = new SiteSettingsService($site, $this->file);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->siteSettingsServices as $service) {
            try {
                $service->removeSetting($this->key);
            } catch (\Exception $e) {
                if ($output->getVerbosity() === OutputInterface::VERBOSITY_DEBUG) {
                    $this->io->error($e->getMessage() . "\n" . $e->getTraceAsString());
                } else {
                    $this->io->error($e->getMessage());
                }
            }
        }
        return Command::SUCCESS;
    }
}
