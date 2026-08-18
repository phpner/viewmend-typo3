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

namespace ViewMend\Typo3SiteTracker\Application;

use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Site\CanonicalUrlPolicy;

final readonly class EventRecorder
{
    public function __construct(
        private ConfigurationResolver $configuration,
        private DeliveryRepositoryInterface $deliveries,
        private CanonicalUrlPolicy $urls,
    ) {
    }

    public function record(OutgoingEvent $event): ?int
    {
        $configuration = $this->configuration->resolve();
        if (
            !$configuration->enabled
            || $configuration->integrationId === ''
            || $configuration->siteIdentifier === ''
            || $configuration->siteUrl === ''
            || !$this->urls->basesMatch($event->siteUrl, $configuration->siteUrl)
        ) {
            return null;
        }

        return $this->deliveries->enqueue($event, $configuration->siteIdentifier);
    }

    /**
     * Connection tests are operational events. They may be queued before content
     * capture is enabled, but only after the complete connection is configured.
     */
    public function recordConnectionTest(OutgoingEvent $event): ?int
    {
        $configuration = $this->configuration->resolve();
        if (
            !$configuration->isConnectionReady()
            || !$this->urls->basesMatch($event->siteUrl, $configuration->siteUrl)
        ) {
            return null;
        }

        return $this->deliveries->enqueue($event, $configuration->siteIdentifier);
    }
}
