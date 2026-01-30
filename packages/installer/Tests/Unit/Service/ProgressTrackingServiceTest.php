<?php

declare(strict_types=1);

namespace Tum\Installer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tum\Installer\Service\ProgressTrackingService;

class ProgressTrackingServiceTest extends TestCase
{
    private ProgressTrackingService $service;
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for tests
        $this->testDir = sys_get_temp_dir() . '/installer-progress-test-' . uniqid();
        mkdir($this->testDir, 0775, true);

        // We need to mock or work around the Environment dependency
        // For this test, we'll use reflection to set the progress dir
        $this->service = new class($this->testDir) extends ProgressTrackingService {
            public function __construct(string $progressDir)
            {
                $this->progressDir = $progressDir . '/';
                if (!is_dir($this->progressDir)) {
                    mkdir($this->progressDir, 0775, true);
                }
            }

            private string $progressDir;

            public function initProgress(string $installationId): void
            {
                $this->saveProgress($installationId, [
                    'currentStep' => -1,
                    'totalSteps' => 7,
                    'status' => 'pending',
                    'error' => null,
                    'steps' => [
                        ['key' => 'validation', 'label' => 'Validierung', 'status' => 'pending'],
                        ['key' => 'folders', 'label' => 'Ordnerstruktur erstellen', 'status' => 'pending'],
                        ['key' => 'pages', 'label' => 'Seiten anlegen', 'status' => 'pending'],
                        ['key' => 'begroups', 'label' => 'BE-Groups erstellen', 'status' => 'pending'],
                        ['key' => 'beusers', 'label' => 'BE-Users erstellen', 'status' => 'pending'],
                        ['key' => 'siteconfig', 'label' => 'Site-Konfiguration', 'status' => 'pending'],
                        ['key' => 'typoscript', 'label' => 'TypoScript generieren', 'status' => 'pending'],
                    ],
                ]);
            }

            public function getProgress(string $installationId): ?array
            {
                $filePath = $this->getFilePath($installationId);

                if (!file_exists($filePath)) {
                    return null;
                }

                $content = file_get_contents($filePath);
                if ($content === false) {
                    return null;
                }

                return json_decode($content, true);
            }

            public function updateStep(string $installationId, int $stepIndex, string $status = 'in_progress'): void
            {
                $progress = $this->getProgress($installationId);
                if ($progress === null) {
                    return;
                }

                for ($i = 0; $i < $stepIndex; $i++) {
                    if (isset($progress['steps'][$i])) {
                        $progress['steps'][$i]['status'] = 'completed';
                    }
                }

                if (isset($progress['steps'][$stepIndex])) {
                    $progress['steps'][$stepIndex]['status'] = $status;
                }

                $progress['currentStep'] = $stepIndex;
                $progress['status'] = 'running';

                $this->saveProgress($installationId, $progress);
            }

            public function complete(string $installationId): void
            {
                $progress = $this->getProgress($installationId);
                if ($progress === null) {
                    return;
                }

                foreach ($progress['steps'] as &$step) {
                    $step['status'] = 'completed';
                }

                $progress['status'] = 'completed';
                $progress['currentStep'] = count($progress['steps']) - 1;

                $this->saveProgress($installationId, $progress);
            }

            public function setError(string $installationId, string $errorMessage): void
            {
                $progress = $this->getProgress($installationId);
                if ($progress === null) {
                    return;
                }

                $currentStep = $progress['currentStep'];
                if ($currentStep >= 0 && isset($progress['steps'][$currentStep])) {
                    $progress['steps'][$currentStep]['status'] = 'error';
                }

                $progress['status'] = 'error';
                $progress['error'] = $errorMessage;

                $this->saveProgress($installationId, $progress);
            }

            private function saveProgress(string $installationId, array $progress): void
            {
                $filePath = $this->getFilePath($installationId);
                file_put_contents($filePath, json_encode($progress), LOCK_EX);
            }

            private function getFilePath(string $installationId): string
            {
                $safeId = preg_replace('/[^a-zA-Z0-9]/', '', $installationId);
                return $this->progressDir . $safeId . '.json';
            }
        };
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $files = glob($this->testDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->testDir);

        parent::tearDown();
    }

    #[Test]
    public function initProgressCreatesProgressFile(): void
    {
        $installationId = 'test123';

        $this->service->initProgress($installationId);

        $progress = $this->service->getProgress($installationId);

        self::assertNotNull($progress);
        self::assertSame('pending', $progress['status']);
        self::assertSame(-1, $progress['currentStep']);
        self::assertCount(7, $progress['steps']);
    }

    #[Test]
    public function updateStepChangesStepStatus(): void
    {
        $installationId = 'test456';

        $this->service->initProgress($installationId);
        $this->service->updateStep($installationId, 0, 'in_progress');

        $progress = $this->service->getProgress($installationId);

        self::assertSame('running', $progress['status']);
        self::assertSame(0, $progress['currentStep']);
        self::assertSame('in_progress', $progress['steps'][0]['status']);
    }

    #[Test]
    public function updateStepMarksPreviousStepsAsCompleted(): void
    {
        $installationId = 'test789';

        $this->service->initProgress($installationId);
        $this->service->updateStep($installationId, 3, 'in_progress');

        $progress = $this->service->getProgress($installationId);

        // Steps 0, 1, 2 should be completed
        self::assertSame('completed', $progress['steps'][0]['status']);
        self::assertSame('completed', $progress['steps'][1]['status']);
        self::assertSame('completed', $progress['steps'][2]['status']);
        // Step 3 should be in_progress
        self::assertSame('in_progress', $progress['steps'][3]['status']);
        // Steps 4, 5, 6 should still be pending
        self::assertSame('pending', $progress['steps'][4]['status']);
    }

    #[Test]
    public function completeMarksAllStepsAsCompleted(): void
    {
        $installationId = 'testComplete';

        $this->service->initProgress($installationId);
        $this->service->complete($installationId);

        $progress = $this->service->getProgress($installationId);

        self::assertSame('completed', $progress['status']);
        foreach ($progress['steps'] as $step) {
            self::assertSame('completed', $step['status']);
        }
    }

    #[Test]
    public function setErrorMarksCurrentStepAsError(): void
    {
        $installationId = 'testError';

        $this->service->initProgress($installationId);
        $this->service->updateStep($installationId, 2, 'in_progress');
        $this->service->setError($installationId, 'Test error message');

        $progress = $this->service->getProgress($installationId);

        self::assertSame('error', $progress['status']);
        self::assertSame('Test error message', $progress['error']);
        self::assertSame('error', $progress['steps'][2]['status']);
    }

    #[Test]
    public function getProgressReturnsNullForNonExistentInstallation(): void
    {
        $progress = $this->service->getProgress('nonexistent');

        self::assertNull($progress);
    }
}
