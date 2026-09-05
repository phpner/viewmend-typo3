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
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\DashboardView;

final class DashboardViewTest extends TestCase
{
    public function testGroupsOnlyExactDuplicatesAndPreservesMissingMeasurements(): void
    {
        $payload = json_decode(file_get_contents(__DIR__ . '/../E2E/FakeApi/dashboard.json'), true);
        $payload['data']['needs_attention']['items'][] = $payload['data']['needs_attention']['items'][0];
        $payload['data']['summary']['health_score'] = null;
        $view = $this->view()->build(['success' =>
            true,
             'sample' =>
            true,
             'cached' =>
            false,
             'message' =>
            null,
             'generated_at' =>
            null,
             'data' =>
            $payload['data']]);
        self::assertCount(4, $view['issues']);
        self::assertEquals(2, $view['issues'][0]['count']);
        self::assertSame(5, $view['issue_loaded']);
        self::assertSame('—', $view['summary']['health_score']);
        self::assertSame('0', $this->view()->value(0));
        self::assertStringStartsWith('/resources?', $view['transfer']['categories'][0]['url']);
    }

    public function testRejectsExternalWorkspaceLinksAndToleratesPartialData(): void
    {
        $view = $this->view();
        self::assertSame('', $view->workspaceUrl('https://evil.example/app/tracker'));
        self::assertSame('', $view->workspaceUrl('//evil.example/app/tracker'));
        self::assertSame('', $view->workspaceUrl('javascript:alert(1)'));
        $result = $view->build(['success' =>
            true,
             'cached' =>
            false,
             'message' =>
            null,
             'generated_at' =>
            null,
             'data' =>
            ['needs_attention' =>
            'invalid',
             'resource_changes' =>
            null]]);
        self::assertFalse($result['has_page']);
        self::assertSame([], $result['issues']);
        self::assertSame('No tracked pages', $result['state']);
    }

    public function testKeepsThirtyChecksAndBreaksTheLineAtMissingValues(): void
    {
        $history = [];
        for ($i = 1; $i <= 31; $i++) {
            $history[] = ['finished_at' =>
                sprintf(
                    '2026-08-%02dT12:00:00Z',
                    $i
                ),
                 'performance_score' =>
                $i === 20 ? null : 90];
        }
        $result = $this->view()->build(['success' =>
            true,
             'cached' =>
            false,
             'message' =>
            null,
             'generated_at' =>
            null,
             'data' =>
            ['performance_history' =>
            $history]]);
        $chart = $result['metrics'][0]['chart'];
        self::assertCount(30, $chart['rows']);
        self::assertSame(2, substr_count($chart['lines'][0]['path'], ' M '));
        self::assertSame('—', $chart['rows'][18]['values'][0]);
    }

    private function view(): DashboardView
    {
        $uris = $this->createMock(UriBuilder::class);
        $uris->method('buildUriFromRoute')->willReturn(new Uri('/resources?run_id=test'));
        return new DashboardView($uris);
    }
}
