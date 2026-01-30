<?php

declare(strict_types=1);

namespace Tum\Installer\Service;

use TYPO3\CMS\Core\Core\Environment;

class ProgressTrackingService
{
    private string $progressDir;

    public function __construct()
    {
        $this->progressDir = Environment::getVarPath() . '/installer-progress/';
        if (!is_dir($this->progressDir)) {
            mkdir($this->progressDir, 0775, true);
        }
    }

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

    public function updateStep(string $installationId, int $stepIndex, string $status = 'in_progress'): void
    {
        $progress = $this->getProgress($installationId);
        if ($progress === null) {
            return;
        }

        // Mark previous steps as completed
        for ($i = 0; $i < $stepIndex; $i++) {
            if (isset($progress['steps'][$i])) {
                $progress['steps'][$i]['status'] = 'completed';
            }
        }

        // Update current step
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

        // Mark all steps as completed
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

        // Mark current step as error
        $currentStep = $progress['currentStep'];
        if ($currentStep >= 0 && isset($progress['steps'][$currentStep])) {
            $progress['steps'][$currentStep]['status'] = 'error';
        }

        $progress['status'] = 'error';
        $progress['error'] = $errorMessage;

        $this->saveProgress($installationId, $progress);
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

    public function cleanup(string $installationId): void
    {
        $filePath = $this->getFilePath($installationId);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    private function saveProgress(string $installationId, array $progress): void
    {
        $filePath = $this->getFilePath($installationId);
        file_put_contents($filePath, json_encode($progress), LOCK_EX);
    }

    private function getFilePath(string $installationId): string
    {
        // Sanitize installationId to prevent directory traversal
        $safeId = preg_replace('/[^a-zA-Z0-9]/', '', $installationId);
        return $this->progressDir . $safeId . '.json';
    }
}
