<?php

declare(strict_types=1);

namespace Tum\Installer\Service;

use Tum\Installer\Domain\Model\WebinfoData;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

class WebinfoApiService
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    public function push(WebinfoData $data, array $installationConfig): void
    {
        $extConf = $this->getExtensionConfiguration();

        $apiUrl = $extConf['webinfoApiUrl'] ?? '';
        $apiKey = $extConf['webinfoApiKey'] ?? '';
        $enabled = (bool)($extConf['webinfoApiEnabled'] ?? true);

        if (!$enabled) {
            // API is disabled - silently skip
            return;
        }

        if (empty($apiUrl)) {
            throw new \RuntimeException('Webinfo API URL nicht konfiguriert. Bitte in den Extension-Einstellungen hinterlegen.');
        }

        // Validate domain - only allow *.tum.de
        $domain = $installationConfig['domain'] ?? '';
        if (!$this->isDomainAllowed($domain)) {
            throw new \RuntimeException('API-Push nur für *.tum.de Domains erlaubt. Aktuelle Domain: ' . $domain);
        }

        // Build payload
        $payload = $this->buildPayload($data, $installationConfig);

        // Send request
        $this->sendRequest($apiUrl, $apiKey, $payload);
    }

    public function isDomainAllowed(string $domain): bool
    {
        if (empty($domain)) {
            return false;
        }

        // Allow *.tum.de domains
        return (bool)preg_match('/\.tum\.de$/i', $domain) || $domain === 'tum.de';
    }

    private function buildPayload(WebinfoData $data, array $installationConfig): array
    {
        // Build URL from domain + navName
        $domain = $installationConfig['domain'] ?? '';
        $navName = $installationConfig['navName'] ?? '';
        $setupType = $installationConfig['type'] ?? '';

        // Handle SetupType enum if present
        if (is_object($setupType) && method_exists($setupType, 'value')) {
            $setupType = $setupType->value;
        }

        $url = 'https://' . $domain;
        if (!empty($navName)) {
            $url .= '/' . $navName;
        }

        return array_merge(
            $data->toArray(),
            [
                'url' => $url,
                'domain' => $domain,
                'nav_name' => $navName,
                'setup' => $setupType,
                'wid' => $installationConfig['wid'] ?? '',
                'created_at' => date('Y-m-d'),
            ]
        );
    }

    private function sendRequest(string $apiUrl, string $apiKey, array $payload): void
    {
        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($payload),
            'timeout' => 30,
        ];

        // Add authorization header if API key is set
        if (!empty($apiKey)) {
            $options['headers']['Authorization'] = 'Bearer ' . $apiKey;
        }

        try {
            $response = $this->requestFactory->request($apiUrl, 'POST', $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $body = $response->getBody()->getContents();
                throw new \RuntimeException(
                    sprintf('Webinfo API Fehler (HTTP %d): %s', $statusCode, $body)
                );
            }
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            throw new \RuntimeException('Webinfo API nicht erreichbar: ' . $e->getMessage());
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $response = $e->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            $body = $response ? $response->getBody()->getContents() : $e->getMessage();
            throw new \RuntimeException(
                sprintf('Webinfo API Fehler (HTTP %d): %s', $statusCode, $body)
            );
        }
    }

    private function getExtensionConfiguration(): array
    {
        try {
            return $this->extensionConfiguration->get('installer');
        } catch (\Exception $e) {
            return [];
        }
    }
}
