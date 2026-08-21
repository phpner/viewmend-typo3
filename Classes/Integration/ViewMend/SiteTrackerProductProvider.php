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

namespace ViewMend\Typo3SiteTracker\Integration\ViewMend;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use ViewMend\Typo3Core\Contract\ProductDashboardProviderInterface;
use ViewMend\Typo3Core\Product\ProductDashboardData;
use ViewMend\Typo3Core\Product\ProductDefinition;
use ViewMend\Typo3Core\Product\ProductMetric;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\SiteTrackerDashboardSummary;

final readonly class SiteTrackerProductProvider implements ProductDashboardProviderInterface
{
    public function __construct(private SiteTrackerDashboardSummary $summary)
    {
    }

    public function product(): ProductDefinition
    {
        return new ProductDefinition(
            identifier: 'site-tracker',
            title: 'Site Tracker',
            description: 'Know what changed after TYPO3 content updates and deployments before visitors report it.',
            composerPackage: 'viewmend/typo3-site-tracker',
            extensionKey: 'viewmend_site_tracker',
            moduleIdentifier: 'tools_viewmendtracker',
            category: 'Monitoring',
            position: 20,
            iconIdentifier: 'viewmend-product-site-tracker',
        );
    }

    public function dashboardData(BackendUserAuthentication $user): ProductDashboardData
    {
        if (!$user->isSystemMaintainer()) {
            return new ProductDashboardData();
        }

        $summary = $this->summary->summary();

        return new ProductDashboardData(
            metadata: [$summary['status']],
            metrics: [
                new ProductMetric('Waiting', (string) $summary['waiting']),
                new ProductMetric('Needs attention', (string) $summary['issues']),
            ],
        );
    }
}
