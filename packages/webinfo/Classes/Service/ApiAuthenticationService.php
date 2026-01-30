<?php

declare(strict_types=1);

namespace Tum\Webinfo\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class ApiAuthenticationService
{
    private array $config;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {
        $this->config = $this->loadConfiguration();
    }

    public function validateRequest(ServerRequestInterface $request): bool|string
    {
        if (!$this->isApiEnabled()) {
            return 'API is disabled';
        }

        $expectedApiKey = $this->getApiKey();
        if (empty($expectedApiKey)) {
            return 'API key not configured';
        }

        $providedKey = $this->extractApiKey($request);
        if (empty($providedKey)) {
            return 'Missing Authorization header';
        }

        if (!hash_equals($expectedApiKey, $providedKey)) {
            return 'Invalid API key';
        }

        return true;
    }

    public function validateOriginDomain(ServerRequestInterface $request): bool|string
    {
        $allowedDomains = $this->getAllowedDomains();
        if (empty($allowedDomains)) {
            // No restrictions configured
            return true;
        }

        // Get origin from request body (the domain that sent the data)
        $body = $request->getParsedBody();
        if (empty($body)) {
            $rawBody = $request->getBody()->getContents();
            $body = json_decode($rawBody, true);
            // Reset body stream for later use
            $request->getBody()->rewind();
        }

        $domain = $body['domain'] ?? '';
        if (empty($domain)) {
            return 'Missing domain in request body';
        }

        if (!$this->isDomainAllowed($domain, $allowedDomains)) {
            return 'Domain not allowed: ' . $domain;
        }

        return true;
    }

    public function isDomainAllowed(string $domain, array $allowedPatterns): bool
    {
        foreach ($allowedPatterns as $pattern) {
            // Convert wildcard pattern to regex
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $domain)) {
                return true;
            }
        }
        return false;
    }

    private function extractApiKey(ServerRequestInterface $request): string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return '';
        }

        // Support "Bearer <token>" format
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return $authHeader;
    }

    public function isApiEnabled(): bool
    {
        return (bool)($this->config['apiEnabled'] ?? true);
    }

    public function getApiKey(): string
    {
        return (string)($this->config['apiKey'] ?? '');
    }

    public function getAllowedDomains(): array
    {
        $domains = $this->config['allowedDomains'] ?? '*.tum.de';
        if (is_string($domains)) {
            return array_filter(array_map('trim', explode(',', $domains)));
        }
        return (array)$domains;
    }

    private function loadConfiguration(): array
    {
        try {
            return $this->extensionConfiguration->get('webinfo');
        } catch (\Exception $e) {
            return [];
        }
    }
}
