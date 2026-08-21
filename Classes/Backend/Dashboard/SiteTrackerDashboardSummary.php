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

namespace ViewMend\Typo3SiteTracker\Backend\Dashboard;

use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;

final readonly class SiteTrackerDashboardSummary
{
    public function __construct(
        private ConfigurationResolver $configuration,
        private DeliveryRepositoryInterface $deliveries,
    ) {
    }

    /** @return array{status:string,waiting:int,issues:int} */
    public function summary(): array
    {
        $configuration = $this->configuration->resolve();
        $counts = $this->deliveries->counts($configuration->siteIdentifier ?: null);

        return [
            'status' => $configuration->isReady() ? 'Connected' : 'Setup required',
            'waiting' => ($counts[DeliveryStatus::Queued->value] ?? 0)
                + ($counts[DeliveryStatus::RetryScheduled->value] ?? 0)
                + ($counts[DeliveryStatus::InProgress->value] ?? 0),
            'issues' => $counts[DeliveryStatus::PermanentlyFailed->value] ?? 0,
        ];
    }
}
