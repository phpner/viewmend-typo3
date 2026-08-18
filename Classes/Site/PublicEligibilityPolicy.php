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

namespace ViewMend\Typo3SiteTracker\Site;

use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class PublicEligibilityPolicy
{
    /** @param array<string, mixed> $row */
    public function isPublic(array $row, int $now): bool
    {
        if (
            ScalarValue::int($row['deleted'] ?? null) !== 0
            || ScalarValue::int($row['hidden'] ?? null) !== 0
            || $this->isWorkspaceRecord($row)
        ) {
            return false;
        }
        $start = ScalarValue::int($row['starttime'] ?? null);
        $end = ScalarValue::int($row['endtime'] ?? null);
        if (($start > 0 && $start > $now) || ($end > 0 && $end <= $now)) {
            return false;
        }
        $groups = trim(ScalarValue::string($row['fe_group'] ?? null));

        return $groups === '' || $groups === '0';
    }

    /** @param array<string, mixed> $row */
    public function isWorkspaceRecord(array $row): bool
    {
        return ScalarValue::int($row['t3ver_wsid'] ?? null) !== 0
            || ScalarValue::int($row['t3ver_state'] ?? null) !== 0;
    }

    /** @param array<string, mixed> $row */
    public function nextTransition(array $row, int $now): int
    {
        $times = array_filter(
            [
                ScalarValue::int($row['starttime'] ?? null),
                ScalarValue::int($row['endtime'] ?? null),
            ],
            static fn (int $value): bool => $value > $now,
        );

        return $times === [] ? 0 : min($times);
    }
}
