<?php
declare(strict_types=1);

namespace Tum\Installer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tum\Installer\Service\SetupService;

#[AsCommand(
    name: 'tum:installer:run',
    description: 'Führt ein Setup aus.'
)]
class RunSetupCommand extends Command
{
    public function __construct(
        private readonly SetupService $setupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('setup', InputArgument::REQUIRED, 'Setup Name (z.B. Setup1)')
            ->addOption('nav-name', null, InputOption::VALUE_REQUIRED, 'Projekt Kürzel')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain')
            ->addOption('site-name-de', null, InputOption::VALUE_REQUIRED, 'Seiten Name DE')
            ->addOption('site-name-en', null, InputOption::VALUE_REQUIRED, 'Seiten Name EN')
            ->addOption('wid', null, InputOption::VALUE_REQUIRED, 'WID')
            ->addOption('lrz-id', null, InputOption::VALUE_REQUIRED, 'LRZ ID')
            ->addOption('parent-ou', null, InputOption::VALUE_REQUIRED, 'Parent OU ID')
            ->addOption('parent-ou-name-de', null, InputOption::VALUE_REQUIRED, 'Parent OU Name DE')
            ->addOption('parent-ou-name-en', null, InputOption::VALUE_REQUIRED, 'Parent OU Name EN')
            ->addOption('parent-ou-url-de', null, InputOption::VALUE_REQUIRED, 'Parent OU URL DE')
            ->addOption('parent-ou-url-en', null, InputOption::VALUE_REQUIRED, 'Parent OU URL EN')
            ->addOption('imprint', null, InputOption::VALUE_REQUIRED, 'Impressum Kontakt Text')
            ->addOption('accessibility', null, InputOption::VALUE_REQUIRED, 'Barrierefreiheit Kontakt Text')
            ->addOption('news', null, InputOption::VALUE_NONE, 'News System aktivieren')
            ->addOption('intropage', null, InputOption::VALUE_NONE, 'Intropage aktivieren')
            ->addOption('curl-content', null, InputOption::VALUE_NONE, 'CurlContent aktivieren')
            ->addOption('member-list', null, InputOption::VALUE_NONE, 'MemberList aktivieren')
            ->addOption('courses', null, InputOption::VALUE_NONE, 'Courses aktivieren')
            ->addOption('vcard', null, InputOption::VALUE_NONE, 'vCard aktivieren');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $setupName = $input->getArgument('setup');

        $config = [
            'navName' => $input->getOption('nav-name'),
            'domain' => $input->getOption('domain'),
            'siteNameDe' => $input->getOption('site-name-de') ?: $input->getOption('nav-name'),
            'siteNameEn' => $input->getOption('site-name-en') ?: $input->getOption('nav-name') . ' (EN)',
            'wid' => $input->getOption('wid'),
            'lrzid' => $input->getOption('lrz-id'),
            'parentOu' => $input->getOption('parent-ou'),
            'parentOuNameDe' => $input->getOption('parent-ou-name-de'),
            'parentOuNameEn' => $input->getOption('parent-ou-name-en'),
            'parentOuUrlDe' => $input->getOption('parent-ou-url-de'),
            'parentOuUrlEn' => $input->getOption('parent-ou-url-en'),
            'imprint' => $input->getOption('imprint'),
            'accessibility' => $input->getOption('accessibility'),
            'news' => $input->getOption('news'),
            'intropage' => $input->getOption('intropage'),
            'curlContent' => $input->getOption('curl-content'),
            'memberList' => $input->getOption('member-list'),
            'courses' => $input->getOption('courses'),
            'vcard' => $input->getOption('vcard'),
        ];

        $io->title("Starte Setup: $setupName");

        try {
            $this->setupService->runSetup($setupName, $config, $io);
            if (!empty($config['navName']) && !empty($config['domain'])) {
                $this->setupService->createSiteConfiguration($config, $setupName, $io);
            }
            $io->success('Setup erfolgreich!');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}