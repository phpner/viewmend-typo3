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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;

final class OutgoingEventTest extends TestCase
{
    public function testRoundTripsOnlyDeclaredOperationalMetadata(): void
    {
        $event = new OutgoingEvent(
            eventId: 'stable-1',
            type: EventType::ContentUpdate,
            title: 'TYPO3 page updated',
            occurredAt: new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            siteUrl: 'https://example.com/',
            pageUrls: ['https://example.com/about'],
            environment: 'production',
            changedFields: ['content'],
            metadata: ['cms' => 'typo3', 'record_uid' => 42],
        );
        $payload = $event->toArray();
        self::assertArrayNotHasKey('body', $payload);
        self::assertArrayNotHasKey('user', $payload);
        self::assertEquals($event, OutgoingEvent::fromArray($payload));
    }

    public function testRejectsNonHttpPublicUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OutgoingEvent(
            eventId: 'event-1',
            type: EventType::Custom,
            title: 'Invalid URL event',
            occurredAt: new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            siteUrl: 'ftp://example.com/',
            pageUrls: [],
            environment: 'production',
        );
    }

    public function testRejectsCredentialsEmbeddedInPublicUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OutgoingEvent(
            eventId: 'event-credentials',
            type: EventType::Deployment,
            title: 'Unsafe reference URL event',
            occurredAt: new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            siteUrl: 'https://example.com/',
            pageUrls: [],
            environment: 'production',
            referenceUrl: 'https://user:secret@example.com/releases/1',
        );
    }
}
