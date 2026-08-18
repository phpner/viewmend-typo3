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
use ViewMend\Typo3SiteTracker\Domain\LifecycleTransition;
use ViewMend\Typo3SiteTracker\Domain\LifecycleTransitionMapper;

final class LifecycleTransitionMapperTest extends TestCase
{
    #[DataProvider('transitions')]
    public function testMapsOnlyConfirmedPublicTransitions(
        ?bool $wasPublic,
        bool $exists,
        bool $isPublic,
        ?string $before,
        ?string $after,
        ?LifecycleTransition $expected,
    ): void {
        self::assertSame($expected, (new LifecycleTransitionMapper())->map(
            $wasPublic,
            $exists,
            $isPublic,
            $before,
            $after,
        ));
    }

    /** @return iterable<string, array{?bool, bool, bool, ?string, ?string, ?LifecycleTransition}> */
    public static function transitions(): iterable
    {
        yield 'first public observation' => [null, true, true, null, 'a', LifecycleTransition::Publish];
        yield 'draft stays private' => [false, true, false, 'a', 'b', null];
        yield 'publish' => [false, true, true, 'a', 'b', LifecycleTransition::Publish];
        yield 'public update' => [true, true, true, 'a', 'b', LifecycleTransition::Update];
        yield 'unchanged save' => [true, true, true, 'a', 'a', null];
        yield 'unpublish' => [true, true, false, 'a', 'a', LifecycleTransition::Unpublish];
        yield 'delete' => [true, false, false, 'a', null, LifecycleTransition::Delete];
        yield 'private delete ignored' => [false, false, false, 'a', null, null];
    }
}
