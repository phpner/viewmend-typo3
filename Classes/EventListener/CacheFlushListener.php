<?php

/*
 * This file is part of the ViewMend Site Tracker extension for TYPO3.
 *
 * Copyright (C) 2026 ViewMend
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. See the LICENSE file distributed with this
 * source code for the full license text.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace ViewMend\Typo3SiteTracker\EventListener;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use ViewMend\Typo3SiteTracker\Application\EventRecorder;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\EventIdFactory;
use ViewMend\Typo3SiteTracker\Support\Redactor;

#[AsEventListener(
    identifier: 'viewmend-site-tracker/cache-flush',
    after: CacheManager::class,
)]
final readonly class CacheFlushListener
{
    public function __construct(
        private CacheManager $cacheManager,
        private ConfigurationResolver $configuration,
        private EventRecorder $events,
        private EventIdFactory $ids,
        private ClockInterface $clock,
        private Redactor $redactor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CacheFlushEvent $event): void
    {
        $configuration = $this->configuration->resolve();
        if (!$configuration->enabled || !$configuration->recordCacheFlush || !$this->isFullFlush($event)) {
            return;
        }
        try {
            $this->events->record(new OutgoingEvent(
                eventId: $this->ids->random('cache-clear'),
                type: EventType::CacheCleared,
                title: 'TYPO3 full cache cleared',
                occurredAt: $this->clock->now(),
                siteUrl: $configuration->siteUrl,
                pageUrls: [],
                environment: $configuration->environment,
                changedFields: ['metadata'],
                metadata: [
                    'cms' => 'typo3',
                    'operation' => 'full_cache_clear',
                    'site_identifier' => $configuration->siteIdentifier,
                ],
            ));
        } catch (Throwable $exception) {
            $this->logger->error('ViewMend cache clear capture failed.', [
                'reason' => $this->redactor->redact($exception->getMessage()),
            ]);
        }
    }

    private function isFullFlush(CacheFlushEvent $event): bool
    {
        $requestedGroups = [];
        foreach ($event->getGroups() as $group) {
            if (!is_string($group) || $group === '') {
                return false;
            }
            $requestedGroups[$group] = true;
        }
        $allGroups = [];
        foreach ($this->cacheManager->getCacheGroups() as $group) {
            $allGroups[$group] = true;
        }

        return $allGroups !== []
            && array_diff_key($requestedGroups, $allGroups) === []
            && array_diff_key($allGroups, $requestedGroups) === [];
    }
}
