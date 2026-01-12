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
    name: 'yamlManager:add',
    description: 'Adds a value to the site config'
)]
class YamlManagerAddCommand extends Command
{
    protected string $file;
    protected string $key;
    protected string $value;

    /**
     * @var array<int, SiteSettingsService>
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
            "Add a new value, or replace an existing value in a site config YAML file.\n\n"
            . "Example usage: typo3 yamlManager:add --site someSite settings page.constants.1.someConstant someNewValue\n"
            . "This will modify a file '{CONFIG_PATH}/sites/someSite/settings.yaml' in the following way:\n\n"
            . "page:\n"
            . "  somethingElse: someValue\n"
            . "  constants:\n"
            . "    - someOtherValue\n"
            . "    - \n"
            . "      somethingelseConstant: someValue\n"
            . "      someConstant: oldValue (<-- this value will be replaced with 'someNewValue')\n\n"
            . 'WARNING: Be careful when modifying existing complex values! The command WILL overwrite the entire value, '
            . "no matter what it contains!\n"
            . "Example: typo3 yamlManager:add --site someSite settings page.constants someNewValue\n"
            . "That command would do the following to the example above:\n\n"
            . "page:\n"
            . "  somethingElse: someValue\n"
            . "  constants: someNewValue\n\n"
            . "The value argument also accepts a JSON string as argument.\n"
            . "Example: typo3 yamlManager:add --site someSite settings page.constants '{\"key1\": [\"value1\", "
            . "{\"subKey1\": \"value2\"}]}'\n"
            . "That command would do the following to the example above:\n\n"
            . "page:\n"
            . "  somethingElse: someValue\n"
            . "  constants:\n"
            . "    key1:\n"
            . "      - value1\n"
            . "      - \n"
            . "        subKey1: value2\n"
        );

        $this->addArgument(
            'file',
            InputArgument::REQUIRED,
            'The name of the site settings YAML file to modify. The file will be created if it does not exist'
            . "\nExample: \'settings\' for {CONFIG_PATH}/sites/{SITE_NAME}/settings.yaml."
        );
        $this->addArgument(
            'key',
            InputArgument::REQUIRED,
            'The YAML key to set / replace. List items can be accessed via their numerical index (see '
            . 'examples below)'
        );
        $this->addArgument(
            'value',
            InputArgument::REQUIRED,
            'The value to set / replace (if it already exists). Also accepts a JSON string for complex values.'
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
        // make sure we do not keep collected sites, command is called within yamlManager:setBaseVariants multiple times
        $this->siteSettingsServices = [];
        $this->file = (string)$input->getArgument('file');
        $this->key = (string)$input->getArgument('key');
        $this->value = (string)$input->getArgument('value');
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

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->siteSettingsServices as $service) {
            try {
                $service->modifySetting($this->key, $this->value);
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
