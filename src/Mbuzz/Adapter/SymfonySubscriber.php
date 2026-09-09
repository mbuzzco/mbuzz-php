<?php

declare(strict_types=1);

namespace Mbuzz\Adapter;

use Mbuzz\Mbuzz;
use Mbuzz\SessionResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony EventSubscriber for Mbuzz tracking
 *
 * Automatically initializes tracking on each request.
 * Register this subscriber in your services.yaml:
 *
 *   services:
 *       Mbuzz\Adapter\SymfonySubscriber:
 *           tags: ['kernel.event_subscriber']
 *
 * Or register it manually:
 *
 *   $dispatcher->addSubscriber(new SymfonySubscriber());
 */
final class SymfonySubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // High priority (256) to run early in the request lifecycle
            // before other subscribers that might need tracking data
            KernelEvents::REQUEST => ['onKernelRequest', 256],
        ];
    }

    /**
     * Initialize Mbuzz tracking on kernel request
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        // Only process main requests, not sub-requests (like ESI, fragments)
        if ($event->getRequestType() !== HttpKernelInterface::MAIN_REQUEST) {
            return;
        }

        // Initialize tracking from the request. This reads cookies and, on
        // POST /_mbuzz/session, mints the visitor cookie.
        if (!Mbuzz::initFromRequest()) {
            return;
        }

        // This request was the session endpoint: answer it here, so the
        // application never has to know the endpoint exists. setResponse()
        // stops propagation and skips the controller.
        $event->setResponse(SessionResponse::symfony());
    }
}
