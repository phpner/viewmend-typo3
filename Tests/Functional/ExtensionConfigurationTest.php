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

namespace ViewMend\Typo3SiteTracker\Tests\Functional;

use PHPUnit\Framework\TestCase;

final class ExtensionConfigurationTest extends TestCase
{
    public function testBackendModuleUsesSystemMaintainerAccessAndPostOnlyMutations(): void
    {
        $modules = require dirname(__DIR__, 2) . '/Configuration/Backend/Modules.php';
        $module = $modules['tools_viewmendtracker'];
        self::assertSame('systemMaintainer', $module['access']);
        self::assertSame(['GET'], $module['routes']['_default']['methods']);
        self::assertSame(['POST'], $module['routes']['connection-save']['methods']);
        self::assertSame(['POST'], $module['routes']['rules-save']['methods']);
        self::assertSame(['POST'], $module['routes']['activity-filter']['methods']);
        self::assertSame(['POST'], $module['routes']['activity-action']['methods']);
    }

    public function testCommandsDeclareCorrectSchedulerBoundaries(): void
    {
        $services = file_get_contents(dirname(__DIR__, 2) . '/Configuration/Services.yaml');
        self::assertIsString($services);
        self::assertStringContainsString("command: 'viewmend:process'", $services);
        self::assertStringContainsString("command: 'viewmend:deployment'", $services);
        self::assertMatchesRegularExpression("/viewmend:process'[\\s\\S]+schedulable: true/", $services);
        self::assertMatchesRegularExpression("/viewmend:deployment'[\\s\\S]+schedulable: false/", $services);
    }
}
