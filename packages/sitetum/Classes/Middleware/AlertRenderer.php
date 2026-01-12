<?php

declare(strict_types=1);

namespace ElementareTeilchen\Sitetum\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Render the potential alert message via PSR-15 middleware
 * IF a file in (symlinked) path /_frontend/alert.html is found, the content is rendered into the page
 */
class AlertRenderer implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $alertFilePath = Environment::getPublicPath() . '/_frontend/alert.html';
        if (is_file($alertFilePath)) {
            $body = $response->getBody();
            $body->rewind();
            $contents = $response->getBody()->getContents();
            $content = str_ireplace(
                '<div id="topbar"',
                GeneralUtility::getUrl($alertFilePath) . '<div id="topbar"',
                $contents
            );
            $body = new Stream('php://temp', 'rw');
            $body->write($content);
            $response = $response->withBody($body);
        }

        return $response;
    }
}
