<?php

declare(strict_types=1);

namespace Tum\Webinfo\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tum\Webinfo\Service\WebsiteService;
use Tum\Webinfo\Service\ApiAuthenticationService;
use TYPO3\CMS\Core\Http\JsonResponse;

class ApiController
{
    public function __construct(
        private readonly WebsiteService $websiteService,
        private readonly ApiAuthenticationService $authService
    ) {}

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        // Validate API Key
        $authResult = $this->authService->validateRequest($request);
        if ($authResult !== true) {
            return new JsonResponse(['error' => $authResult], 401);
        }

        // Validate Origin Domain
        $domainResult = $this->authService->validateOriginDomain($request);
        if ($domainResult !== true) {
            return new JsonResponse(['error' => $domainResult], 403);
        }

        // Parse request body
        $body = $request->getParsedBody();
        if (empty($body)) {
            $rawBody = $request->getBody()->getContents();
            $body = json_decode($rawBody, true);
        }

        if (empty($body)) {
            return new JsonResponse(['error' => 'Empty request body'], 400);
        }

        // Validate required fields
        $requiredFields = ['url', 'domain'];
        foreach ($requiredFields as $field) {
            if (empty($body[$field])) {
                return new JsonResponse(['error' => "Missing required field: $field"], 400);
            }
        }

        try {
            $website = $this->websiteService->createFromArray($body);

            return new JsonResponse([
                'success' => true,
                'message' => 'Website created successfully',
                'data' => [
                    'uid' => $website->getUid(),
                    'url' => $website->getUrl(),
                ]
            ], 201);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to create website: ' . $e->getMessage()
            ], 500);
        }
    }

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        // Validate API Key
        $authResult = $this->authService->validateRequest($request);
        if ($authResult !== true) {
            return new JsonResponse(['error' => $authResult], 401);
        }

        try {
            $queryParams = $request->getQueryParams();
            $searchTerm = $queryParams['search'] ?? '';
            $limit = (int)($queryParams['limit'] ?? 100);
            $offset = (int)($queryParams['offset'] ?? 0);

            $websites = $this->websiteService->findAll($searchTerm, $limit, $offset);
            $total = $this->websiteService->countAll($searchTerm);

            return new JsonResponse([
                'success' => true,
                'data' => array_map(fn($w) => $w->toArray(), $websites),
                'meta' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch websites: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAction(ServerRequestInterface $request): ResponseInterface
    {
        // Validate API Key
        $authResult = $this->authService->validateRequest($request);
        if ($authResult !== true) {
            return new JsonResponse(['error' => $authResult], 401);
        }

        $uid = (int)($request->getQueryParams()['uid'] ?? 0);
        if ($uid <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid uid'], 400);
        }

        try {
            $website = $this->websiteService->findByUid($uid);

            if ($website === null) {
                return new JsonResponse(['error' => 'Website not found'], 404);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $website->toArray()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch website: ' . $e->getMessage()
            ], 500);
        }
    }
}
