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

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ViewMend\Typo3SiteTracker\Domain\RetryPolicy;
use ViewMend\Typo3SiteTracker\Tests\Support\FixedJitter;

final class RetryPolicyTest extends TestCase
{
    public function testUsesBoundedBackoffAndRetryAfter(): void
    {
        $policy = new RetryPolicy(new FixedJitter());
        $now = new DateTimeImmutable('2026-08-18T12:00:00+00:00');
        self::assertSame(60, $policy->decide(1, true, null, $now)->delaySeconds);
        self::assertSame(600, $policy->decide(2, true, '600', $now)->delaySeconds);
        self::assertSame(900, $policy->decide(3, true, 'invalid', $now)->delaySeconds);
        self::assertFalse($policy->decide(1, false, null, $now)->retry);
        self::assertFalse($policy->decide(8, true, null, $now)->retry);
    }
}
