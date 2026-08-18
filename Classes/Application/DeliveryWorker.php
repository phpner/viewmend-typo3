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

namespace ViewMend\Typo3SiteTracker\Application;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\SettingsRepositoryInterface;
use ViewMend\Typo3SiteTracker\Domain\RetryPolicy;
use ViewMend\Typo3SiteTracker\Persistence\Delivery;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Transport\DeliveryException;
use ViewMend\Typo3SiteTracker\Transport\DeliveryTransportInterface;

final readonly class DeliveryWorker
{
    public function __construct(
        private ConfigurationResolver $configuration,
        private SettingsRepositoryInterface $settings,
        private DeliveryRepositoryInterface $deliveries,
        private DeliveryTransportInterface $transport,
        private RetryPolicy $retryPolicy,
        private Redactor $redactor,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $leaseSeconds = 180,
    ) {
    }

    public function process(int $limit = 20): WorkerReport
    {
        $limit = max(1, min(100, $limit));
        $now = $this->clock->now();
        $recovered = $this->deliveries->recoverExpiredLeases($now);
        $counts = ['claimed' => 0, 'accepted' => 0, 'duplicates' => 0, 'retried' => 0, 'failed' => 0];

        for ($index = 0; $index < $limit; $index++) {
            $delivery = $this->deliveries->claimNext($this->clock->now(), $this->leaseSeconds);
            if ($delivery === null) {
                break;
            }
            $counts['claimed']++;
            $outcome = $this->deliver($delivery);
            $counts[$outcome]++;
        }

        $configuration = $this->configuration->resolve();
        $before = $this->clock->now()->sub(new DateInterval('P' . $configuration->retentionDays . 'D'));
        $pruned = $this->deliveries->pruneDeliveredBefore($before);
        $report = new WorkerReport(
            recovered: $recovered,
            claimed: $counts['claimed'],
            accepted: $counts['accepted'],
            duplicates: $counts['duplicates'],
            retried: $counts['retried'],
            failed: $counts['failed'],
            pruned: $pruned,
        );
        $this->settings->updateOperationalState([
            'last_worker_at' => $this->clock->now()->getTimestamp(),
            'last_worker_summary' => $report->summary(),
        ]);

        return $report;
    }

    public function processOne(int $uid): ?string
    {
        $delivery = $this->deliveries->claimByUid($uid, $this->clock->now(), $this->leaseSeconds);

        return $delivery === null ? null : $this->deliver($delivery);
    }

    /** @return 'accepted'|'duplicates'|'retried'|'failed' */
    private function deliver(Delivery $delivery): string
    {
        $startedAt = $this->clock->now();
        $configuration = $this->configuration->resolve();
        if (!$configuration->isConnectionReady()) {
            return $this->permanentFailure($delivery, $startedAt, null, 'ViewMend connection is not ready.');
        }
        if ($delivery->siteIdentifier !== $configuration->siteIdentifier) {
            return $this->permanentFailure(
                $delivery,
                $startedAt,
                null,
                'The event belongs to a different TYPO3 site binding and was not sent.',
            );
        }
        $apiToken = (string) $configuration->apiToken;

        try {
            $response = $this->transport->send(
                $delivery->event,
                $configuration->integrationId,
                $apiToken,
            );
            $duplicate = (bool) ($response['duplicate'] ?? false);
            $finishedAt = $this->clock->now();
            if (!$this->deliveries->markSuccessful($delivery, $duplicate, $response, $finishedAt)) {
                $this->logger->warning('ViewMend delivery lease was lost before success could be recorded.', [
                    'event_id' => $delivery->eventId,
                ]);
                return 'retried';
            }
            $this->deliveries->recordAttempt(
                $delivery,
                $startedAt,
                $finishedAt,
                $duplicate ? 'duplicate' : 'accepted',
                $duplicate ? 200 : 202,
                $duplicate ? 'ViewMend confirmed a duplicate event.' : 'ViewMend accepted the event.',
            );

            return $duplicate ? 'duplicates' : 'accepted';
        } catch (DeliveryException $exception) {
            return $this->handleDeliveryException($delivery, $startedAt, $exception, $apiToken);
        } catch (JsonException $exception) {
            return $this->permanentFailure($delivery, $startedAt, null, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->permanentFailure(
                $delivery,
                $startedAt,
                null,
                $this->redactor->redact(
                    'Unexpected delivery failure: ' . $exception->getMessage(),
                    [$apiToken],
                ),
            );
        }
    }

    /** @return 'retried'|'failed' */
    private function handleDeliveryException(
        Delivery $delivery,
        DateTimeImmutable $startedAt,
        DeliveryException $exception,
        #[\SensitiveParameter] string $token,
    ): string {
        $finishedAt = $this->clock->now();
        $error = $this->redactor->redact($exception->getMessage(), [$token]);
        $decision = $this->retryPolicy->decide(
            $delivery->attemptCount,
            $exception->retryable,
            $exception->retryAfter,
            $finishedAt,
        );
        if ($decision->retry) {
            $nextAttempt = $finishedAt->add(new DateInterval('PT' . $decision->delaySeconds . 'S'));
            $retryScheduled = $this->deliveries->scheduleRetry(
                $delivery,
                $nextAttempt,
                $exception->httpStatus,
                $error,
                $finishedAt,
            );
            if (!$retryScheduled) {
                $this->logger->warning('ViewMend delivery lease was lost before a retry could be scheduled.', [
                    'event_id' => $delivery->eventId,
                ]);
                return 'retried';
            }
            $this->deliveries->recordAttempt(
                $delivery,
                $startedAt,
                $finishedAt,
                'retry_scheduled',
                $exception->httpStatus,
                $error,
            );
            $this->logger->notice('ViewMend delivery was scheduled for retry.', [
                'event_id' => $delivery->eventId,
                'delay_seconds' => $decision->delaySeconds,
                'http_status' => $exception->httpStatus,
            ]);
            return 'retried';
        }

        if ($decision->reason === 'attempt_limit') {
            $error .= ' Retry limit reached; review the connection and retry manually.';
        }

        return $this->permanentFailure($delivery, $startedAt, $exception->httpStatus, $error);
    }

    /** @return 'failed' */
    private function permanentFailure(
        Delivery $delivery,
        DateTimeImmutable $startedAt,
        ?int $httpStatus,
        string $error,
    ): string {
        $finishedAt = $this->clock->now();
        $error = $this->redactor->redact($error);
        if (!$this->deliveries->markPermanentFailure($delivery, $httpStatus, $error, $finishedAt)) {
            $this->logger->warning('ViewMend delivery lease was lost before a failure could be recorded.', [
                'event_id' => $delivery->eventId,
            ]);
            return 'failed';
        }
        $this->deliveries->recordAttempt($delivery, $startedAt, $finishedAt, 'permanently_failed', $httpStatus, $error);
        $this->settings->updateOperationalState(['last_error' => $error]);
        $this->logger->error('ViewMend delivery permanently failed.', [
            'event_id' => $delivery->eventId,
            'http_status' => $httpStatus,
            'reason' => $error,
        ]);

        return 'failed';
    }
}
