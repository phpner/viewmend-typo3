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

use DateTimeImmutable;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;

interface DeliveryRepositoryInterface
{
    public function enqueue(OutgoingEvent $event, string $siteIdentifier): int;

    public function claimNext(DateTimeImmutable $now, int $leaseSeconds): ?Delivery;

    public function claimByUid(int $uid, DateTimeImmutable $now, int $leaseSeconds): ?Delivery;

    /** @param array<string, int|string|bool|null> $response */
    public function markSuccessful(Delivery $delivery, bool $duplicate, array $response, DateTimeImmutable $now): bool;

    public function scheduleRetry(
        Delivery $delivery,
        DateTimeImmutable $nextAttempt,
        ?int $httpStatus,
        string $error,
        DateTimeImmutable $now,
    ): bool;

    public function markPermanentFailure(
        Delivery $delivery,
        ?int $httpStatus,
        string $error,
        DateTimeImmutable $now,
    ): bool;

    public function recordAttempt(
        Delivery $delivery,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        string $outcome,
        ?int $httpStatus,
        string $message,
    ): void;

    public function recoverExpiredLeases(DateTimeImmutable $now): int;

    public function retry(int $uid, DateTimeImmutable $now): bool;

    public function cancel(int $uid, DateTimeImmutable $now): bool;

    /** @return array<string, int> */
    public function counts(?string $siteIdentifier = null): array;

    /** @return list<array<string, mixed>> */
    public function findRecent(?DeliveryStatus $status, ?string $siteIdentifier, int $limit = 100): array;

    /** @return list<array<string, mixed>> */
    public function attempts(int $deliveryUid): array;

    /** @return array<string, mixed>|null */
    public function findRow(int $uid): ?array;

    public function pruneDeliveredBefore(DateTimeImmutable $before): int;
}
