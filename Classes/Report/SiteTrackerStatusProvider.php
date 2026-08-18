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

namespace ViewMend\Typo3SiteTracker\Report;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\SettingsRepositoryInterface;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class SiteTrackerStatusProvider implements StatusProviderInterface
{
    public function __construct(
        private ConfigurationResolver $configuration,
        private SettingsRepositoryInterface $settings,
        private DeliveryRepositoryInterface $deliveries,
        private ClockInterface $clock,
    ) {
    }

    public function getStatus(): array
    {
        $configuration = $this->configuration->resolve();
        $counts = $this->deliveries->counts($configuration->siteIdentifier ?: null);
        $raw = $this->settings->getRaw();
        $waiting = $counts[DeliveryStatus::Queued->value]
            + $counts[DeliveryStatus::RetryScheduled->value]
            + $counts[DeliveryStatus::InProgress->value];
        $failed = $counts[DeliveryStatus::PermanentlyFailed->value];
        $lastWorker = ScalarValue::int($raw['last_worker_at'] ?? null);
        $workerStale = $waiting > 0 && ($lastWorker === 0 || $lastWorker < $this->clock->now()->getTimestamp() - 900);

        return [
            new Status(
                'Connection',
                $configuration->isConnectionReady() ? 'Ready' : 'Needs configuration',
                $configuration->isConnectionReady()
                    ? sprintf(
                        'Bound to site %s using token source %s.',
                        $configuration->siteIdentifier,
                        $configuration->tokenSource,
                    )
                    : 'Open Admin Tools > ViewMend Site Tracker and complete Connection.',
                $configuration->isConnectionReady()
                    ? ContextualFeedbackSeverity::OK
                    : ContextualFeedbackSeverity::WARNING,
            ),
            new Status(
                'Event capture',
                $configuration->enabled ? 'Enabled' : 'Disabled',
                $configuration->enabled
                    ? 'Confirmed public lifecycle changes are recorded locally before delivery.'
                    : 'New lifecycle events are not recorded. Already queued events can still be delivered.',
                $configuration->enabled ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::NOTICE,
            ),
            new Status(
                'Delivery queue',
                sprintf('%d waiting, %d failed', $waiting, $failed),
                $failed > 0
                    ? 'Review failed deliveries in the Activity section.'
                    : 'No permanent delivery failures need attention.',
                $failed > 0 ? ContextualFeedbackSeverity::ERROR : ContextualFeedbackSeverity::OK,
            ),
            new Status(
                'Background worker',
                $workerStale ? 'Stale or not scheduled' : 'Operational',
                $workerStale
                    ? 'Run vendor/bin/typo3 viewmend:process every minute using cron or TYPO3 Scheduler.'
                    : ScalarValue::string(
                        $raw['last_worker_summary'] ?? null,
                        'No queued work requires a worker run.',
                    ),
                $workerStale ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK,
            ),
        ];
    }

    public function getLabel(): string
    {
        return 'ViewMend Site Tracker';
    }
}
