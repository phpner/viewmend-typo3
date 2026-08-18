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

namespace ViewMend\Typo3SiteTracker\Tests\Integration;

use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\TestCase;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Tests\Support\SequenceHttpClient;
use ViewMend\Typo3SiteTracker\Transport\DeliveryException;
use ViewMend\Typo3SiteTracker\Transport\ViewMendClientFactoryInterface;
use ViewMend\Typo3SiteTracker\Transport\ViewMendSdkTransport;
use ViewMend\ViewMend;

final class ViewMendSdkTransportTest extends TestCase
{
    /** @throws JsonException */
    public function testUsesPublishedSdkContractAndPreservesStableEventId(): void
    {
        $body = json_encode([
            'ok' => true,
            'delivery_id' => 'delivery-1',
            'event_id' => 'server-event-1',
            'duplicate' => false,
            'affected_pages' => 1,
            'ignored_urls' => 0,
            'checks_queued' => 1,
            'queue_status' => 'queued',
            'scheduled_for' => null,
        ], JSON_THROW_ON_ERROR);
        $http = new SequenceHttpClient([
            new Response(202, ['Content-Type' => 'application/json', 'X-ViewMend-Delivery' => 'delivery-1'], $body),
        ]);
        $factory = new class ($http) implements ViewMendClientFactoryInterface {
            public function __construct(private readonly SequenceHttpClient $http)
            {
            }

            public function create(#[\SensitiveParameter] string $apiToken): ViewMend
            {
                $factory = new HttpFactory();
                return ViewMend::withPsr18($apiToken, $this->http, $factory, $factory);
            }
        };
        $transport = new ViewMendSdkTransport($factory);
        $result = $transport->send(new OutgoingEvent(
            eventId: 'stable-deploy-42',
            type: EventType::Deployment,
            title: 'TYPO3 deployment',
            occurredAt: new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            siteUrl: 'https://example.com/',
            pageUrls: ['https://example.com/about'],
            environment: 'production',
            referenceUrl: 'https://example.com/releases/42',
            changedFields: ['metadata'],
            metadata: ['cms' => 'typo3', 'operation' => 'deployment'],
        ), 'integration/id', 'private-token');

        self::assertFalse($result['duplicate']);
        self::assertSame('delivery-1', $result['delivery_id']);
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame(
            'https://viewmend.com/api/v1/site-tracker/integrations/integration%2Fid/events',
            (string) $request->getUri(),
        );
        $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('stable-deploy-42', $payload['event_id']);
        self::assertSame('deployment', $payload['event_type']);
        self::assertArrayNotHasKey('body', $payload);
        self::assertStringNotContainsString('private-token', (string) $request->getBody());
    }

    /** @throws JsonException */
    public function testMapsSdkDuplicateAndPermanentApiError(): void
    {
        $duplicateBody = json_encode([
            'ok' => true,
            'delivery_id' => 'delivery-duplicate',
            'event_id' => 'server-event-duplicate',
            'duplicate' => true,
            'affected_pages' => 0,
            'ignored_urls' => 0,
            'checks_queued' => 0,
            'queue_status' => 'duplicate',
            'scheduled_for' => null,
        ], JSON_THROW_ON_ERROR);
        $http = new SequenceHttpClient([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-ViewMend-Delivery' => 'delivery-duplicate',
            ], $duplicateBody),
            new Response(422, ['Content-Type' => 'application/json'], '{"message":"invalid event"}'),
        ]);
        $transport = new ViewMendSdkTransport($this->factory($http));
        $event = new OutgoingEvent(
            eventId: 'stable-duplicate',
            type: EventType::Custom,
            title: 'TYPO3 event',
            occurredAt: new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            siteUrl: 'https://example.com/',
            pageUrls: [],
            environment: 'production',
        );

        self::assertTrue($transport->send($event, 'integration', 'private-token')['duplicate']);
        try {
            $transport->send($event, 'integration', 'private-token');
            self::fail('A permanent API error must not be accepted.');
        } catch (DeliveryException $exception) {
            self::assertFalse($exception->retryable);
            self::assertSame(422, $exception->httpStatus);
        }
        self::assertCount(2, $http->requests);
    }

    private function factory(SequenceHttpClient $http): ViewMendClientFactoryInterface
    {
        return new class ($http) implements ViewMendClientFactoryInterface {
            public function __construct(private readonly SequenceHttpClient $http)
            {
            }

            public function create(#[\SensitiveParameter] string $apiToken): ViewMend
            {
                $factory = new HttpFactory();
                return ViewMend::withPsr18($apiToken, $this->http, $factory, $factory);
            }
        };
    }
}
