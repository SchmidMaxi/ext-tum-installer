<?php

declare(strict_types=1);

namespace ElementareTeilchen\Sitetum\EventListener;

use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;

/** @TODO v14: use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ScopeType; */
final class SitemapCspEventListener
{
    /** @var int[] Page types, für die die CSP gesetzt werden soll */
    private const PAGE_TYPES_TO_CHECK = [1533906435, 1533906436];

    public function __invoke(PolicyMutatedEvent $event): void
    {
        // Nur im FE wirken
        // TODO v14: if ($event->scope->type === ScopeType::backend) {
        if ($event->scope->type->isBackend()) {
            return;
        }

        $routing = $event->request->getAttribute('routing');

        // Abbrechen, wenn kein Routing vorhanden oder PageType nicht in der Liste
        if (
            !$routing instanceof PageArguments
            || !\in_array((int)$routing->getPageType(), self::PAGE_TYPES_TO_CHECK, true)
        ) {
            return;
        }

        // Define a new policy for the XML sitemap
        $policy = new Policy();
        $policy->set(Directive::DefaultSrc, SourceKeyword::none);
        $policy->set(Directive::ScriptSrcElem, SourceKeyword::self);
        $policy->set(Directive::StyleSrc, SourceKeyword::unsafeInline);

        $event->setCurrentPolicy($policy);
        $event->stopPropagation();
    }
}
