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

namespace ViewMend\Typo3SiteTracker\Domain;

use LogicException;

final readonly class DeliveryStateMachine
{
    /** @var array<string, list<DeliveryStatus>> */
    private const TRANSITIONS = [
        'queued' => [DeliveryStatus::InProgress, DeliveryStatus::Cancelled],
        'retry_scheduled' => [DeliveryStatus::InProgress, DeliveryStatus::Cancelled],
        'in_progress' => [
            DeliveryStatus::Accepted,
            DeliveryStatus::Duplicate,
            DeliveryStatus::RetryScheduled,
            DeliveryStatus::PermanentlyFailed,
        ],
        'permanently_failed' => [DeliveryStatus::Queued, DeliveryStatus::Cancelled],
        'accepted' => [],
        'duplicate' => [],
        'cancelled' => [],
    ];

    public function assertTransition(DeliveryStatus $from, DeliveryStatus $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from->value], true)) {
            throw new LogicException(sprintf('Invalid delivery transition from %s to %s.', $from->value, $to->value));
        }
    }
}
