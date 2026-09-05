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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStateMachine;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class DoctrineDeliveryRepository implements DeliveryRepositoryInterface
{
    private const TABLE = 'tx_viewmendsitetracker_delivery';
    private const ATTEMPT_TABLE = 'tx_viewmendsitetracker_attempt';

    public function __construct(
        private ConnectionProviderInterface $connections,
        private DeliveryStateMachine $stateMachine,
        private ClockInterface $clock,
    ) {
    }

    public function enqueue(OutgoingEvent $event, string $siteIdentifier): int
    {
        $now = $this->clock->now()->getTimestamp();
        $connection = $this->connections->forTable(self::TABLE);
        try {
            $connection->insert(self::TABLE, [
                'event_id' => $event->eventId,
                'site_identifier' => $siteIdentifier,
                'event_type' => $event->type->value,
                'title' => $event->title,
                'payload' => $event->toJson(),
                'status' => DeliveryStatus::Queued->value,
                'attempt_count' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $connection->lastInsertId();
        } catch (UniqueConstraintViolationException) {
            $existing = $connection->createQueryBuilder()
                ->select('uid')
                ->from(self::TABLE)
                ->where('event_id = :eventId')
                ->andWhere('site_identifier = :siteIdentifier')
                ->setParameter('eventId', $event->eventId)
                ->setParameter('siteIdentifier', $siteIdentifier)
                ->executeQuery()
                ->fetchOne();

            return ScalarValue::int($existing);
        }
    }

    public function claimNext(DateTimeImmutable $now, int $leaseSeconds): ?Delivery
    {
        $connection = $this->connections->forTable(self::TABLE);
        $query = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('status IN (:statuses)')
            ->andWhere('next_attempt_at <= :now')
            ->andWhere('(locked_until = 0 OR locked_until < :now)')
            ->setParameter(
                'statuses',
                [DeliveryStatus::Queued->value, DeliveryStatus::RetryScheduled->value],
                ArrayParameterType::STRING,
            )
            ->setParameter('now', $now->getTimestamp(), ParameterType::INTEGER)
            ->orderBy('next_attempt_at', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(1);
        $row = $query->executeQuery()->fetchAssociative();

        return is_array($row) ? $this->claimRow($row, $now, $leaseSeconds) : null;
    }

    public function claimByUid(int $uid, DateTimeImmutable $now, int $leaseSeconds): ?Delivery
    {
        $row = $this->findRow($uid);
        if (
            $row === null || !in_array(
                ScalarValue::string($row['status'] ?? null),
                [DeliveryStatus::Queued->value, DeliveryStatus::RetryScheduled->value],
                true,
            )
        ) {
            return null;
        }

        return $this->claimRow($row, $now, $leaseSeconds);
    }

    public function markSuccessful(Delivery $delivery, bool $duplicate, array $response, DateTimeImmutable $now): bool
    {
        $target = $duplicate ? DeliveryStatus::Duplicate : DeliveryStatus::Accepted;
        $this->stateMachine->assertTransition($delivery->status, $target);

        return $this->leasedUpdate($delivery, [
            'status' => $target->value,
            'api_delivery_id' => (string) ($response['delivery_id'] ?? ''),
            'api_event_id' => (string) ($response['event_id'] ?? ''),
            'queue_status' => (string) ($response['queue_status'] ?? ''),
            'response_summary' => (string) ($response['summary'] ?? ''),
            'last_http_status' => $duplicate ? 200 : 202,
            'last_error' => '',
            'lease_token' => '',
            'locked_until' => 0,
            'updated_at' => $now->getTimestamp(),
            'delivered_at' => $now->getTimestamp(),
        ]);
    }

    public function scheduleRetry(
        Delivery $delivery,
        DateTimeImmutable $nextAttempt,
        ?int $httpStatus,
        string $error,
        DateTimeImmutable $now,
    ): bool {
        $this->stateMachine->assertTransition($delivery->status, DeliveryStatus::RetryScheduled);

        return $this->leasedUpdate($delivery, [
            'status' => DeliveryStatus::RetryScheduled->value,
            'next_attempt_at' => $nextAttempt->getTimestamp(),
            'last_http_status' => $httpStatus ?? 0,
            'last_error' => $error,
            'lease_token' => '',
            'locked_until' => 0,
            'updated_at' => $now->getTimestamp(),
        ]);
    }

    public function markPermanentFailure(
        Delivery $delivery,
        ?int $httpStatus,
        string $error,
        DateTimeImmutable $now,
    ): bool {
        $this->stateMachine->assertTransition($delivery->status, DeliveryStatus::PermanentlyFailed);

        return $this->leasedUpdate($delivery, [
            'status' => DeliveryStatus::PermanentlyFailed->value,
            'last_http_status' => $httpStatus ?? 0,
            'last_error' => $error,
            'lease_token' => '',
            'locked_until' => 0,
            'updated_at' => $now->getTimestamp(),
        ]);
    }

    public function recordAttempt(
        Delivery $delivery,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
        string $outcome,
        ?int $httpStatus,
        string $message,
    ): void {
        try {
            $this->connections->forTable(self::ATTEMPT_TABLE)->insert(self::ATTEMPT_TABLE, [
                'delivery_uid' => $delivery->uid,
                'attempt_number' => $delivery->attemptCount,
                'started_at' => $startedAt->getTimestamp(),
                'finished_at' => $finishedAt->getTimestamp(),
                'outcome' => $outcome,
                'http_status' => $httpStatus ?? 0,
                'message' => $message,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A recovered worker may finish after its lease expired. Preserve one
            // authoritative attempt row for each claimed attempt number.
        }
    }

    public function recoverExpiredLeases(DateTimeImmutable $now): int
    {
        return $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', DeliveryStatus::RetryScheduled->value)
            ->set('next_attempt_at', $now->getTimestamp(), true, ParameterType::INTEGER)
            ->set('lease_token', '')
            ->set('locked_until', 0, true, ParameterType::INTEGER)
            ->set('last_error', 'A previous worker lease expired; the delivery was safely recovered.')
            ->set('updated_at', $now->getTimestamp(), true, ParameterType::INTEGER)
            ->where('status = :inProgress')
            ->andWhere('locked_until > 0')
            ->andWhere('locked_until < :now')
            ->setParameter('inProgress', DeliveryStatus::InProgress->value)
            ->setParameter('now', $now->getTimestamp(), ParameterType::INTEGER)
            ->executeStatement();
    }

    public function retry(int $uid, DateTimeImmutable $now): bool
    {
        $this->stateMachine->assertTransition(DeliveryStatus::PermanentlyFailed, DeliveryStatus::Queued);
        $count = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', DeliveryStatus::Queued->value)
            ->set('attempt_count', 0, true, ParameterType::INTEGER)
            ->set('next_attempt_at', $now->getTimestamp(), true, ParameterType::INTEGER)
            ->set('last_error', '')
            ->set('last_http_status', 0, true, ParameterType::INTEGER)
            ->set('updated_at', $now->getTimestamp(), true, ParameterType::INTEGER)
            ->where('uid = :uid')
            ->andWhere('status = :failed')
            ->setParameter('failed', DeliveryStatus::PermanentlyFailed->value)
            ->setParameter('uid', $uid, ParameterType::INTEGER)
            ->executeStatement();

        return $count === 1;
    }

    public function cancel(int $uid, DateTimeImmutable $now): bool
    {
        $cancellable = [
            DeliveryStatus::Queued->value,
            DeliveryStatus::RetryScheduled->value,
            DeliveryStatus::PermanentlyFailed->value,
        ];
        $count = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', DeliveryStatus::Cancelled->value)
            ->set('lease_token', '')
            ->set('locked_until', 0, true, ParameterType::INTEGER)
            ->set('updated_at', $now->getTimestamp(), true, ParameterType::INTEGER)
            ->where('uid = :uid')
            ->andWhere('status IN (:statuses)')
            ->setParameter('statuses', $cancellable, ArrayParameterType::STRING)
            ->setParameter('uid', $uid, ParameterType::INTEGER)
            ->executeStatement();

        return $count === 1;
    }

    public function counts(?string $siteIdentifier = null): array
    {
        $query = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->select('status')
            ->addSelectLiteral('COUNT(*) AS amount')
            ->from(self::TABLE)
            ->groupBy('status');
        if ($siteIdentifier !== null && $siteIdentifier !== '') {
            $query->where('site_identifier = :site')->setParameter('site', $siteIdentifier);
        }
        $counts = array_fill_keys(array_map(
            static fn (DeliveryStatus $status): string => $status->value,
            DeliveryStatus::cases(),
        ), 0);
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $status = ScalarValue::string($row['status'] ?? null);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = ScalarValue::int($row['amount'] ?? null);
            }
        }

        return $counts;
    }

    public function findRecent(
        ?DeliveryStatus $status,
        ?string $siteIdentifier,
        int $limit = 100,
        int $offset = 0,
        string $search = ''
    ): array {
        return $this->activityQuery($status, $siteIdentifier, $search)->select('*')
            ->orderBy('uid', 'DESC')->setMaxResults(max(1, min(200, $limit)))
            ->setFirstResult(max(0, $offset))->executeQuery()->fetchAllAssociative();
    }

    public function countRecent(?DeliveryStatus $status, ?string $siteIdentifier, string $search = ''): int
    {
        return ScalarValue::int($this->activityQuery($status, $siteIdentifier, $search)
            ->count('uid')->executeQuery()->fetchOne());
    }

    private function activityQuery(
        ?DeliveryStatus $status,
        ?string $siteIdentifier,
        string $search
    ): \TYPO3\CMS\Core\Database\Query\QueryBuilder {
        $query = $this->connections->forTable(self::TABLE)->createQueryBuilder()->from(self::TABLE);
        if ($status !== null) {
            $query->andWhere('status = :status')->setParameter('status', $status->value);
        }
        if ($siteIdentifier !== null && $siteIdentifier !== '') {
            $query->andWhere('site_identifier = :site')->setParameter('site', $siteIdentifier);
        }
        $search = mb_substr(trim($search), 0, 200);
        if ($search !== '') {
            $columns = ['event_id', 'event_type', 'title', 'payload', 'api_delivery_id', 'api_event_id'];
            $query->andWhere('(' . implode(' OR ', array_map(
                static fn (string $column): string => $column . " LIKE :search ESCAPE '!'",
                $columns,
            )) . ')')->setParameter('search', '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        }
        return $query;
    }

    public function attempts(int $deliveryUid): array
    {
        return $this->connections->forTable(self::ATTEMPT_TABLE)->createQueryBuilder()
            ->select('*')
            ->from(self::ATTEMPT_TABLE)
            ->where('delivery_uid = :uid')
            ->setParameter('uid', $deliveryUid, ParameterType::INTEGER)
            ->orderBy('attempt_number', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findRow(int $uid): ?array
    {
        $row = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function pruneDeliveredBefore(DateTimeImmutable $before): int
    {
        $connection = $this->connections->forTable(self::TABLE);
        $rows = $connection->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->where('status IN (:statuses)')
            ->andWhere('delivered_at > 0')
            ->andWhere('delivered_at < :before')
            ->setParameter(
                'statuses',
                [DeliveryStatus::Accepted->value, DeliveryStatus::Duplicate->value],
                ArrayParameterType::STRING,
            )
            ->setParameter('before', $before->getTimestamp(), ParameterType::INTEGER)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($rows === []) {
            return 0;
        }
        $uids = array_map(static fn (mixed $value): int => ScalarValue::int($value), $rows);
        $attemptConnection = $this->connections->forTable(self::ATTEMPT_TABLE);
        $attemptConnection->createQueryBuilder()
            ->delete(self::ATTEMPT_TABLE)
            ->where('delivery_uid IN (:uids)')
            ->setParameter('uids', $uids, ArrayParameterType::INTEGER)
            ->executeStatement();

        return $connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('uid IN (:uids)')
            ->setParameter('uids', $uids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /** @param array<string, mixed> $row */
    private function claimRow(array $row, DateTimeImmutable $now, int $leaseSeconds): ?Delivery
    {
        $status = DeliveryStatus::from(ScalarValue::string($row['status'] ?? null));
        $this->stateMachine->assertTransition($status, DeliveryStatus::InProgress);
        $leaseToken = bin2hex(random_bytes(24));
        $connection = $this->connections->forTable(self::TABLE);
        $count = $connection->createQueryBuilder()
            ->update(self::TABLE)
            ->set('status', DeliveryStatus::InProgress->value)
            ->set('attempt_count', 'attempt_count + 1', false)
            ->set('lease_token', $leaseToken)
            ->set('locked_until', $now->getTimestamp() + max(30, $leaseSeconds), true, ParameterType::INTEGER)
            ->set('updated_at', $now->getTimestamp(), true, ParameterType::INTEGER)
            ->where('uid = :uid')
            ->andWhere('status = :expectedStatus')
            ->andWhere('(locked_until = 0 OR locked_until < :now)')
            ->setParameter('now', $now->getTimestamp(), ParameterType::INTEGER)
            ->setParameter('uid', ScalarValue::int($row['uid'] ?? null), ParameterType::INTEGER)
            ->setParameter('expectedStatus', $status->value)
            ->executeStatement();
        if ($count !== 1) {
            return null;
        }
        $row['status'] = DeliveryStatus::InProgress->value;
        $row['attempt_count'] = ScalarValue::int($row['attempt_count'] ?? null) + 1;
        $row['lease_token'] = $leaseToken;
        $row['locked_until'] = $now->getTimestamp() + max(30, $leaseSeconds);

        return Delivery::fromRow($row);
    }

    /** @param array<string, int|string> $values */
    private function leasedUpdate(Delivery $delivery, array $values): bool
    {
        $query = $this->connections->forTable(self::TABLE)->createQueryBuilder()->update(self::TABLE);
        foreach ($values as $column => $value) {
            $query->set(
                $column,
                $value,
                true,
                is_int($value) ? ParameterType::INTEGER : ParameterType::STRING,
            );
        }
        $count = $query
            ->where('uid = :uid')
            ->andWhere('status = :status')
            ->andWhere('lease_token = :leaseToken')
            ->setParameter('uid', $delivery->uid, ParameterType::INTEGER)
            ->setParameter('status', DeliveryStatus::InProgress->value)
            ->setParameter('leaseToken', $delivery->leaseToken)
            ->executeStatement();

        return $count === 1;
    }
}
