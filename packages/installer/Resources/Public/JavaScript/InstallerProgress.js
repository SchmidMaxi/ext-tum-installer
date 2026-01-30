/**
 * TUM Installer Progress Tracking
 * Handles AJAX form submission and progress polling
 */
class InstallerProgress {
    constructor(options = {}) {
        this.form = document.getElementById('installerForm');
        this.progressContainer = document.getElementById('progressContainer');
        this.formContainer = document.getElementById('formContainer');
        this.installationId = null;
        this.pollingInterval = null;
        this.pollingDelay = options.pollingDelay || 500;

        if (this.form) {
            this.init();
        }
    }

    init() {
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.startInstallation();
        });
    }

    async startInstallation() {
        // Hide form, show progress
        if (this.formContainer) {
            this.formContainer.classList.add('d-none');
        }
        if (this.progressContainer) {
            this.progressContainer.classList.remove('d-none');
        }

        const formData = new FormData(this.form);

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls.installer_execute, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.installationId) {
                this.installationId = result.installationId;
                this.startPolling();
            }

            if (!result.success && result.error) {
                this.showError(result.error);
            }
        } catch (error) {
            this.showError('Netzwerkfehler: ' + error.message);
        }
    }

    startPolling() {
        this.pollingInterval = setInterval(() => this.pollProgress(), this.pollingDelay);
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    async pollProgress() {
        if (!this.installationId) return;

        try {
            const url = TYPO3.settings.ajaxUrls.installer_progress + '&installationId=' + encodeURIComponent(this.installationId);
            const response = await fetch(url);
            const progress = await response.json();

            if (progress.error && response.status !== 200) {
                return;
            }

            this.updateProgressUI(progress);

            if (progress.status === 'completed') {
                this.stopPolling();
                this.showSuccess();
            } else if (progress.status === 'error') {
                this.stopPolling();
                this.showError(progress.error || 'Unbekannter Fehler');
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }

    updateProgressUI(progress) {
        if (!progress.steps) return;

        progress.steps.forEach((step, index) => {
            const stepElement = document.querySelector(`[data-step="${step.key}"]`);
            if (!stepElement) return;

            // Update status class
            stepElement.classList.remove('step-pending', 'step-running', 'step-completed', 'step-error');
            stepElement.classList.add('step-' + step.status.replace('in_progress', 'running'));

            // Update icon
            const iconElement = stepElement.querySelector('.step-icon');
            if (iconElement) {
                if (step.status === 'completed') {
                    iconElement.innerHTML = '<span class="text-success">&#10003;</span>';
                } else if (step.status === 'in_progress') {
                    iconElement.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>';
                } else if (step.status === 'error') {
                    iconElement.innerHTML = '<span class="text-danger">&#10007;</span>';
                } else {
                    iconElement.innerHTML = '<span class="text-muted">&#9675;</span>';
                }
            }
        });
    }

    showSuccess() {
        const successElement = document.getElementById('installationSuccess');
        if (successElement) {
            successElement.classList.remove('d-none');
        }

        // Show notification if available
        if (typeof top !== 'undefined' && top.TYPO3 && top.TYPO3.Notification) {
            top.TYPO3.Notification.success('Installation erfolgreich', 'Weiter zu Schritt 2...', 3);
        }

        // Redirect to step 2 after delay
        setTimeout(() => {
            window.location.href = window.location.href.replace(/\/index$/, '/step2').replace(/action=index/, 'action=step2');
            // Fallback: reload page (will show step 2 if session is set)
            if (!window.location.href.includes('step2')) {
                window.location.reload();
            }
        }, 2000);
    }

    showError(message) {
        const errorElement = document.getElementById('installationError');
        const errorMessageElement = document.getElementById('errorMessage');

        if (errorElement) {
            errorElement.classList.remove('d-none');
        }
        if (errorMessageElement) {
            errorMessageElement.textContent = message;
        }

        // Show notification if available
        if (typeof top !== 'undefined' && top.TYPO3 && top.TYPO3.Notification) {
            top.TYPO3.Notification.error('Installation fehlgeschlagen', message, 10);
        }

        // Show retry button
        const retryButton = document.getElementById('retryButton');
        if (retryButton) {
            retryButton.classList.remove('d-none');
            retryButton.addEventListener('click', () => {
                window.location.reload();
            });
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Only initialize if we're on step 1 (form exists)
    if (document.getElementById('installerForm')) {
        window.installerProgress = new InstallerProgress();
    }
});
