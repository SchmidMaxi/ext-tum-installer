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
    description: 'Führt ein Setup aus und erstellt optional die Site Config.'
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
            ->addArgument('setup', InputArgument::REQUIRED, 'Name des Setups (z.B. Setup1)')
            // Wir definieren Optionen für die Config-Variablen in deinen YAMLs
            ->addOption('nav-name', null, InputOption::VALUE_REQUIRED, 'Name für {$navName} und Site Config')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain für {$domain} und Site Config')
            ->addOption('wid', null, InputOption::VALUE_REQUIRED, 'WID Kennung für {$wid}')
            ->addOption('lrz-id', null, InputOption::VALUE_REQUIRED, 'LRZ ID (falls benötigt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $setupName = $input->getArgument('setup');

        // Config-Array aus den Optionen bauen
        $config = [
            'navName' => $input->getOption('nav-name'),
            'domain' => $input->getOption('domain'),
            'wid' => $input->getOption('wid'),
            'lrzid' => $input->getOption('lrz-id'),
        ];

        // Validierung (Minimal)
        if (empty($config['navName']) || empty($config['domain'])) {
            $io->warning('NavName oder Domain fehlen. Site Config wird eventuell nicht erstellt.');
        }

        $io->title(sprintf('Starte Setup: %s', $setupName));

        try {
            // 1. DB Import
            $io->section('Importiere Datenbank-Daten...');
            $this->setupService->runSetup($setupName, $config);
            $io->success('Datenbank-Import abgeschlossen.');

            // 2. Site Config erstellen
            if (!empty($config['navName']) && !empty($config['domain'])) {
                $io->section('Erstelle Site Configuration...');
                $this->setupService->createSiteConfiguration($config);
                $io->success('Site Configuration erstellt.');
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($io->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}