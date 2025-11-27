<?php
declare(strict_types=1);

namespace Tum\Installer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tum\Installer\Service\SetupService;

// Das Attribut "AsCommand" definiert den Namen, den du im Terminal tippst.
#[AsCommand(
    name: 'tum:installer:run',
    description: 'Führt ein definiertes Setup aus (z.B. Setup1, Setup3).'
)]
class RunSetupCommand extends Command
{
    public function __construct(
        private readonly SetupService $setupService
    ) {
        parent::__construct();
    }

    /**
     * Hier konfigurieren wir Argumente (Was muss man eingeben?)
     */
    protected function configure(): void
    {
        $this->addArgument(
            'setup',
            InputArgument::REQUIRED,
            'Der Name des Setups (z.B. Setup1)'
        );
    }

    /**
     * Hier passiert die eigentliche Arbeit
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle macht hübsche Ausgaben (Farben, Boxen)
        $io = new SymfonyStyle($input, $output);

        $setupName = $input->getArgument('setup');

        $io->title(sprintf('Starte TUM Installer Setup: %s', $setupName));

        try {
            // Aufruf unseres Services aus dem vorigen Schritt
            $this->setupService->runSetup($setupName);

            $io->success('Setup wurde erfolgreich durchgeführt!');
            return Command::SUCCESS;

        } catch (\Throwable $exception) {
            // Fehler fangen und rot ausgeben
            $io->error(sprintf('Fehler beim Setup: %s', $exception->getMessage()));

            // Stacktrace anzeigen bei Bedarf ( -v )
            if ($io->isVerbose()) {
                $io->writeln($exception->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}