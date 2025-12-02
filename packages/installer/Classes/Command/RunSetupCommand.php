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
    description: 'Führt ein definiertes Setup aus (z.B. Setup1) und erstellt die Site Config.'
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
            ->addArgument('setup', InputArgument::REQUIRED, 'Der Name des Setups (z.B. Setup1)')
            ->addOption('nav-name', null, InputOption::VALUE_REQUIRED, 'Name für {$navName}')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain für {$domain}')
            ->addOption('wid', null, InputOption::VALUE_REQUIRED, 'WID Kennung')
            ->addOption('lrz-id', null, InputOption::VALUE_REQUIRED, 'LRZ ID')
            ->addOption('news', null, InputOption::VALUE_NONE, 'News Kategorien anlegen?');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $setupName = $input->getArgument('setup');

        $config = [
            'navName' => $input->getOption('nav-name'),
            'domain' => $input->getOption('domain'),
            'wid' => $input->getOption('wid'),
            'lrzid' => $input->getOption('lrz-id'),
            'news' => $input->getOption('news'),
        ];

        $io->title(sprintf('Starte TUM Installer Setup: %s', $setupName));

        try {
            // WICHTIG: Wir übergeben jetzt $io an den Service für Debug-Ausgaben!
            $this->setupService->runSetup($setupName, $config, $io);

            if (!empty($config['navName']) && !empty($config['domain'])) {
                $io->section('Erstelle Site Configuration...');
                $this->setupService->createSiteConfiguration($config, $io);
            }

            $io->success('Setup wurde erfolgreich durchgeführt!');
            return Command::SUCCESS;

        } catch (\Throwable $exception) {
            $io->error(sprintf('Fehler beim Setup: %s', $exception->getMessage()));
            if ($io->isVerbose()) {
                $io->writeln($exception->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}