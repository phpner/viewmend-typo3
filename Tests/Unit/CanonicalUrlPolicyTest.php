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
use ViewMend\Typo3SiteTracker\Site\CanonicalUrlPolicy;

final class CanonicalUrlPolicyTest extends TestCase
{
    public function testAcceptsOnlyBoundAbsoluteHttpUrls(): void
    {
        $policy = new CanonicalUrlPolicy();
        self::assertTrue($policy->basesMatch('https://Example.com/site/', 'https://example.com/site'));
        self::assertFalse($policy->basesMatch('https://other.example/', 'https://example.com/'));
        self::assertTrue($policy->isPublicHttpUrl('https://example.com/about'));
        self::assertFalse($policy->isPublicHttpUrl('/about'));
        self::assertFalse($policy->isPublicHttpUrl('javascript:alert(1)'));
        self::assertFalse($policy->isPublicHttpUrl('https://user:password@example.com/about'));
        self::assertTrue($policy->belongsToBase(
            'https://example.com/site/about?preview=0',
            'https://example.com/site/',
        ));
        self::assertFalse($policy->belongsToBase('https://example.com/site-other', 'https://example.com/site/'));
        self::assertFalse($policy->belongsToBase('https://other.example/site/about', 'https://example.com/site/'));
        self::assertFalse($policy->belongsToBase(
            'https://user:secret@example.com/site/about',
            'https://example.com/site/',
        ));
    }
}
