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

namespace ViewMend\Typo3SiteTracker\Persistence;

use JsonException;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class Delivery
{
    public function __construct(
        public int $uid,
        public string $eventId,
        public string $siteIdentifier,
        public DeliveryStatus $status,
        public int $attemptCount,
        public int $nextAttemptAt,
        public string $leaseToken,
        public int $lockedUntil,
        public OutgoingEvent $event,
        public int $createdAt,
        public int $updatedAt,
        public string $lastError,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        try {
            $payload = json_decode(ScalarValue::string($row['payload'] ?? null), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException('The locally stored event payload is invalid.', previous: $exception);
        }
        if (!is_array($payload)) {
            throw new JsonException('The locally stored event payload is invalid.');
        }

        $eventData = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $eventData[$key] = $value;
            }
        }

        return new self(
            uid: ScalarValue::int($row['uid'] ?? null),
            eventId: ScalarValue::string($row['event_id'] ?? null),
            siteIdentifier: ScalarValue::string($row['site_identifier'] ?? null),
            status: DeliveryStatus::from(ScalarValue::string($row['status'] ?? null)),
            attemptCount: ScalarValue::int($row['attempt_count'] ?? null),
            nextAttemptAt: ScalarValue::int($row['next_attempt_at'] ?? null),
            leaseToken: ScalarValue::string($row['lease_token'] ?? null),
            lockedUntil: ScalarValue::int($row['locked_until'] ?? null),
            event: OutgoingEvent::fromArray($eventData),
            createdAt: ScalarValue::int($row['created_at'] ?? null),
            updatedAt: ScalarValue::int($row['updated_at'] ?? null),
            lastError: ScalarValue::string($row['last_error'] ?? null),
        );
    }
}
