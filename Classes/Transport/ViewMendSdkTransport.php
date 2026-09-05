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

namespace ViewMend\Typo3SiteTracker\Transport;

use Throwable;
use ViewMend\Exception\ApiException;
use ViewMend\Exception\NetworkException;
use ViewMend\Exception\RateLimitException;
use ViewMend\Exception\ServerException;
use ViewMend\Exception\TransportException;
use ViewMend\Exception\UnexpectedResponseException;
use ViewMend\SiteTracker\PendingEvent;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;

final readonly class ViewMendSdkTransport implements DeliveryTransportInterface
{
    public function __construct(private ViewMendClientFactoryInterface $clients)
    {
    }

    public function send(
        OutgoingEvent $event,
        string $integrationId,
        #[\SensitiveParameter] string $apiToken,
    ): array {
        try {
            $events = $this->clients->create($apiToken)->siteTracker($integrationId)->events();
            $pending = match ($event->type) {
                EventType::Deployment => $events->deployment($event->eventId, $event->title),
                EventType::ContentUpdate => $events->contentUpdate($event->eventId, $event->title),
                EventType::PluginUpdate => $events->pluginUpdate($event->eventId, $event->title),
                EventType::CacheCleared => $events->cacheCleared($event->eventId, $event->title),
                EventType::Maintenance => $events->maintenance($event->eventId, $event->title),
                EventType::Custom => $events->custom($event->eventId, $event->title),
            };
            $pending = $pending
                ->occurredAt($event->occurredAt)
                ->site($event->siteUrl)
                ->environment($event->environment);
            foreach ($event->pageUrls as $url) {
                $pending = $pending->page($url);
            }
            if ($event->description !== null) {
                $pending = $pending->description($event->description);
            }
            if ($event->referenceUrl !== null) {
                $pending = $pending->reference($event->referenceUrl);
            }
            $pending = $this->addChangedFields($pending, $event->changedFields);
            if ($event->metadata !== []) {
                $pending = $pending->metadata($event->metadata);
            }
            $result = $pending->send();

            return [
                'delivery_id' => $result->deliveryId->value,
                'event_id' => $result->eventId->value,
                'duplicate' => $result->duplicate,
                'queue_status' => $result->queueStatus->value,
                'summary' => json_encode([
                    'affected_pages' => $result->affectedPages,
                    'ignored_urls' => $result->ignoredUrls,
                    'checks_queued' => $result->checksQueued,
                    'scheduled_for' => $result->scheduledFor?->format(DATE_ATOM),
                ], JSON_THROW_ON_ERROR),
            ];
        } catch (RateLimitException $exception) {
            throw new DeliveryException(
                $exception->getMessage(),
                true,
                $exception->statusCode,
                $exception->retryAfter,
            );
        } catch (ServerException $exception) {
            throw new DeliveryException($exception->getMessage(), true, $exception->statusCode);
        } catch (NetworkException $exception) {
            throw new DeliveryException($exception->getMessage(), true);
        } catch (UnexpectedResponseException $exception) {
            throw new DeliveryException($exception->getMessage(), true, $exception->statusCode);
        } catch (ApiException $exception) {
            throw new DeliveryException($exception->getMessage(), false, $exception->statusCode);
        } catch (TransportException $exception) {
            throw new DeliveryException($exception->getMessage(), false);
        } catch (Throwable $exception) {
            throw new DeliveryException(
                'The ViewMend SDK could not deliver the event: ' . $exception->getMessage(),
                false,
            );
        }
    }

    /** @param list<string> $fields */
    private function addChangedFields(PendingEvent $pending, array $fields): PendingEvent
    {
        foreach ($fields as $field) {
            $pending = match ($field) {
                'content' => $pending->contentChanged(),
                'metadata' => $pending->metadataChanged(),
                default => $pending->customFieldChanged($field),
            };
        }

        return $pending;
    }
}
