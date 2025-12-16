<?php
declare(strict_types=1);

namespace Tum\Installer\Command;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tum\Installer\Domain\Model\InstallationConfig;
use Tum\Installer\Domain\Model\SetupType;
use Tum\Installer\Service\InstallerService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[AsCommand(
    name: 'tum:installer:run',
    description: 'Führt den TUM Site Installer aus (Setup1, Setup3, Standalone, Archiv)'
)]
class RunSetupCommand extends Command
{
    public function __construct(
        private readonly InstallerService       $installerService,
        private readonly ExtensionConfiguration $extensionConfiguration
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('setup', InputArgument::REQUIRED, 'Art des Setups (Setup1, Setup3, Standalone, Archiv)')

            // Basis Daten
            ->addOption('nav-name', null, InputOption::VALUE_REQUIRED, 'Projekt Kürzel (navName)', '')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain', '')
            ->addOption('wid', null, InputOption::VALUE_REQUIRED, 'WID', '')

            // Parent / Archiv Daten
            ->addOption('parent-ou', null, InputOption::VALUE_REQUIRED, 'Parent School/Einheit (z.B. CIT)', '')
            ->addOption('department', null, InputOption::VALUE_REQUIRED, 'Department (nur für Archiv)', '')

            // Metadaten
            ->addOption('site-name-de', null, InputOption::VALUE_REQUIRED, 'Seitenname DE', '')
            ->addOption('site-name-en', null, InputOption::VALUE_REQUIRED, 'Seitenname EN', '')
            ->addOption('parent-ou-name-de', null, InputOption::VALUE_REQUIRED, 'Parent Name DE', '')
            ->addOption('parent-ou-name-en', null, InputOption::VALUE_REQUIRED, 'Parent Name EN', '')
            ->addOption('parent-ou-url-de', null, InputOption::VALUE_REQUIRED, 'Parent URL DE', '')
            ->addOption('parent-ou-url-en', null, InputOption::VALUE_REQUIRED, 'Parent URL EN', '')
            ->addOption('imprint', null, InputOption::VALUE_REQUIRED, 'Impressum Text', '')
            ->addOption('accessibility', null, InputOption::VALUE_REQUIRED, 'Barrierefreiheit Text', '')
            ->addOption('matomo-id', null, InputOption::VALUE_REQUIRED, 'Matomo ID', '')

            // Features (Flags)
            ->addOption('news', null, InputOption::VALUE_NONE, 'Installiert News System')
            ->addOption('intropage', null, InputOption::VALUE_NONE, 'Aktiviert Intropage')
            ->addOption('curl-content', null, InputOption::VALUE_NONE, 'Aktiviert CurlContent')
            ->addOption('member-list', null, InputOption::VALUE_NONE, 'Aktiviert MemberList')
            ->addOption('courses', null, InputOption::VALUE_NONE, 'Aktiviert TUM Courses')
            ->addOption('vcard', null, InputOption::VALUE_NONE, 'Aktiviert TUM vCard');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $setupName = $input->getArgument('setup');
        $type = SetupType::tryFrom($setupName);

        if (!$type) {
            $io->error(sprintf('Ungültiger Setup Typ "%s". Erlaubt sind: %s', $setupName, implode(', ', array_column(SetupType::cases(), 'value'))));
            return Command::FAILURE;
        }

        $io->title("Starte TUM Installer: " . $type->value);

        $navName = (string)$input->getOption('nav-name');
        $domain = (string)$input->getOption('domain');
        $wid = (string)$input->getOption('wid');

        if ($type === SetupType::ARCHIV) {
            try {
                $extConf = $this->extensionConfiguration->get('installer');
                if (empty($domain)) $domain = $extConf['archivDomain'] ?? '';
            } catch (Exception $e) {}
        }

        $config = new InstallationConfig(
            type: $type,
            navName: $navName,
            domain: $domain,
            wid: $wid,
            parentOu: (string)$input->getOption('parent-ou'),
            department: (string)$input->getOption('department'),
            siteNameDe: (string)$input->getOption('site-name-de'),
            siteNameEn: (string)$input->getOption('site-name-en'),
            parentOuNameDe: (string)$input->getOption('parent-ou-name-de'),
            parentOuNameEn: (string)$input->getOption('parent-ou-name-en'),
            parentOuUrlDe: (string)$input->getOption('parent-ou-url-de'),
            parentOuUrlEn: (string)$input->getOption('parent-ou-url-en'),
            imprint: str_replace('\n', "\n", (string)$input->getOption('imprint')),
            accessibility: str_replace('\n', "\n", (string)$input->getOption('accessibility')),
            matomoId: (string)$input->getOption('matomo-id'),

            // Features
            hasNews: (bool)$input->getOption('news'),
            hasIntropage: (bool)$input->getOption('intropage'),
            hasCurlContent: (bool)$input->getOption('curl-content'),
            hasMemberList: (bool)$input->getOption('member-list'),
            hasCourses: (bool)$input->getOption('courses'),
            hasVcard: (bool)$input->getOption('vcard')
        );

        try {
            $this->installerService->install($config);
            $io->success('Installation erfolgreich abgeschlossen!');
            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}