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

namespace ViewMend\Typo3SiteTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\SiteTrackerDashboardSummary;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\SecretVault;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Tests\Support\InMemorySettingsRepository;

final class SiteTrackerDashboardSummaryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('VIEWMEND_API_TOKEN');
    }

    public function testReportsOperationalQueueCountsAndSetupState(): void
    {
        $summary = $this->summary([
            DeliveryStatus::Queued->value => 2,
            DeliveryStatus::InProgress->value => 1,
            DeliveryStatus::RetryScheduled->value => 3,
            DeliveryStatus::PermanentlyFailed->value => 4,
        ]);

        self::assertSame([
            'status' => 'Setup required',
            'waiting' => 6,
            'issues' => 4,
        ], $summary->summary());
    }

    public function testReportsConnectedWhenCaptureAndCredentialsAreReady(): void
    {
        putenv('VIEWMEND_API_TOKEN=local-test-token');
        $summary = $this->summary([], [
            'enabled' => 1,
            'integration_id' => 'integration-id',
            'site_identifier' => 'main',
            'site_url' => 'https://example.test/',
        ]);

        self::assertSame('Connected', $summary->summary()['status']);
    }

    /**
     * @param array<string,int> $counts
     * @param array<string,mixed> $settings
     */
    private function summary(array $counts, array $settings = []): SiteTrackerDashboardSummary
    {
        $counts = array_replace(array_fill_keys(array_map(
            static fn(DeliveryStatus $status): string => $status->value,
            DeliveryStatus::cases(),
        ), 0), $counts);
        $deliveries = $this->createStub(DeliveryRepositoryInterface::class);
        $deliveries->method('counts')->willReturn($counts);
        $configuration = new ConfigurationResolver(
            new InMemorySettingsRepository($settings),
            new SecretVault(),
            new Redactor(),
        );

        return new SiteTrackerDashboardSummary($configuration, $deliveries);
    }
}
