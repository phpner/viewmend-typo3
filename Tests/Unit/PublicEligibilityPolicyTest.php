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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ViewMend\Typo3SiteTracker\Site\PublicEligibilityPolicy;

final class PublicEligibilityPolicyTest extends TestCase
{
    #[DataProvider('records')]
    public function testEvaluatesAnonymousLiveVisibility(array $row, bool $expected): void
    {
        self::assertSame($expected, (new PublicEligibilityPolicy())->isPublic($row, 1_700_000_000));
    }

    /** @return iterable<string, array{array<string, int|string>, bool}> */
    public static function records(): iterable
    {
        $public = [
            'hidden' => 0,
            'deleted' => 0,
            'starttime' => 0,
            'endtime' => 0,
            'fe_group' => '0',
            't3ver_wsid' => 0,
            't3ver_state' => 0,
        ];
        yield 'public live record' => [$public, true];
        yield 'hidden' => [array_merge($public, ['hidden' => 1]), false];
        yield 'deleted' => [array_merge($public, ['deleted' => 1]), false];
        yield 'future start' => [array_merge($public, ['starttime' => 1_700_000_100]), false];
        yield 'expired' => [array_merge($public, ['endtime' => 1_699_999_999]), false];
        yield 'frontend group' => [array_merge($public, ['fe_group' => '3']), false];
        yield 'workspace' => [array_merge($public, ['t3ver_wsid' => 1]), false];
        yield 'delete placeholder' => [array_merge($public, ['t3ver_state' => 2]), false];
    }

    public function testFindsNextScheduledBoundary(): void
    {
        $policy = new PublicEligibilityPolicy();
        self::assertSame(110, $policy->nextTransition(['starttime' => 110, 'endtime' => 120], 100));
        self::assertSame(0, $policy->nextTransition(['starttime' => 90, 'endtime' => 100], 100));
    }
}
