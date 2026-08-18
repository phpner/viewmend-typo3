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
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Package\Cache\PackageDependentCacheIdentifier;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStateMachine;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Persistence\ConnectionProviderInterface;
use ViewMend\Typo3SiteTracker\Persistence\DoctrineDeliveryRepository;
use ViewMend\Typo3SiteTracker\Tests\Support\FrozenClock;
use ViewMend\Typo3SiteTracker\Tests\Support\StaticPackageDependentCacheIdentifier;
use ViewMend\Typo3SiteTracker\Tests\Support\UnrestrictedTestConnection;

final class DeliveryRepositoryTest extends TestCase
{
    private Connection $connection;
    private FrozenClock $clock;
    private DoctrineDeliveryRepository $repository;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][PackageDependentCacheIdentifier::class]['className'] =
            StaticPackageDependentCacheIdentifier::class;
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        foreach (['runtime', 'database_schema'] as $identifier) {
            if (!$cacheManager->hasCache($identifier)) {
                $cacheManager->registerCache(new VariableFrontend($identifier, $this->memoryBackend()));
            }
        }
        $this->connection = new UnrestrictedTestConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            new Driver(),
            new Configuration(),
        );
        $this->connection->executeStatement('CREATE TABLE tx_viewmendsitetracker_delivery (
            uid INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL, site_identifier TEXT NOT NULL,
            event_type TEXT NOT NULL, title TEXT NOT NULL, payload TEXT NOT NULL, status TEXT NOT NULL,
            attempt_count INTEGER NOT NULL DEFAULT 0, next_attempt_at INTEGER NOT NULL DEFAULT 0,
            lease_token TEXT NOT NULL DEFAULT \'\', locked_until INTEGER NOT NULL DEFAULT 0,
            api_delivery_id TEXT NOT NULL DEFAULT \'\', api_event_id TEXT NOT NULL DEFAULT \'\',
            queue_status TEXT NOT NULL DEFAULT \'\', response_summary TEXT NOT NULL DEFAULT \'\',
            last_http_status INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, delivered_at INTEGER NOT NULL DEFAULT 0,
            UNIQUE (site_identifier, event_id)
        )');
        $this->connection->executeStatement('CREATE TABLE tx_viewmendsitetracker_attempt (
            uid INTEGER PRIMARY KEY AUTOINCREMENT, delivery_uid INTEGER NOT NULL, attempt_number INTEGER NOT NULL,
            started_at INTEGER NOT NULL, finished_at INTEGER NOT NULL, outcome TEXT NOT NULL,
            http_status INTEGER NOT NULL DEFAULT 0, message TEXT NOT NULL DEFAULT \'\',
            UNIQUE (delivery_uid, attempt_number)
        )');
        $provider = new class ($this->connection) implements ConnectionProviderInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function forTable(string $table): Connection
            {
                return $this->connection;
            }
        };
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-18T12:00:00+00:00'));
        $this->repository = new DoctrineDeliveryRepository($provider, new DeliveryStateMachine(), $this->clock);
    }

    public function testPersistsBeforeClaimAndPreventsParallelDoubleClaim(): void
    {
        $uid = $this->repository->enqueue($this->event(), 'main');
        self::assertSame($uid, $this->repository->enqueue($this->event(), 'main'));
        $claimed = $this->repository->claimNext($this->clock->now(), 120);
        self::assertNotNull($claimed);
        self::assertSame(DeliveryStatus::InProgress, $claimed->status);
        self::assertNull($this->repository->claimNext($this->clock->now(), 120));

        self::assertTrue($this->repository->markSuccessful($claimed, false, [
            'delivery_id' => 'remote-1',
            'event_id' => 'server-1',
            'queue_status' => 'queued',
            'summary' => 'affected=1 ignored=0 checks=1',
        ], $this->clock->now()), json_encode($this->repository->findRow($uid), JSON_THROW_ON_ERROR));
        self::assertSame(
            1,
            $this->repository->counts('main')[DeliveryStatus::Accepted->value],
            json_encode([
                'row' => $this->repository->findRow($uid),
                'counts' => $this->repository->counts('main'),
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function testStableEventIdsAreDeduplicatedWithinButNotAcrossSiteBindings(): void
    {
        $mainUid = $this->repository->enqueue($this->event(), 'main');

        self::assertSame($mainUid, $this->repository->enqueue($this->event(), 'main'));
        self::assertNotSame($mainUid, $this->repository->enqueue($this->event(), 'secondary'));
    }

    public function testRecoversExpiredLeaseAndKeepsActiveFailuresDuringPrune(): void
    {
        $uid = $this->repository->enqueue($this->event(), 'main');
        self::assertNotNull($this->repository->claimByUid($uid, $this->clock->now(), 30));
        $this->clock->set(new DateTimeImmutable('2026-08-18T12:01:00+00:00'));
        self::assertSame(
            1,
            $this->repository->recoverExpiredLeases($this->clock->now()),
            json_encode($this->repository->findRow($uid), JSON_THROW_ON_ERROR),
        );
        self::assertSame(DeliveryStatus::RetryScheduled->value, $this->repository->findRow($uid)['status']);
        self::assertSame(
            0,
            $this->repository->pruneDeliveredBefore(new DateTimeImmutable('2030-01-01T00:00:00+00:00')),
        );
    }

    public function testManualRetryAndCancelRespectTheStateMachine(): void
    {
        $uid = $this->repository->enqueue($this->event(), 'main');
        $claimed = $this->repository->claimByUid($uid, $this->clock->now(), 30);
        self::assertNotNull($claimed);
        self::assertTrue($this->repository->markPermanentFailure(
            $claimed,
            422,
            'The event was rejected.',
            $this->clock->now(),
        ));

        self::assertTrue($this->repository->retry($uid, $this->clock->now()));
        self::assertFalse($this->repository->retry($uid, $this->clock->now()));
        self::assertTrue($this->repository->cancel($uid, $this->clock->now()));
        self::assertFalse($this->repository->cancel($uid, $this->clock->now()));
        self::assertSame(DeliveryStatus::Cancelled->value, $this->repository->findRow($uid)['status']);
    }

    public function testRetentionPrunesOnlyDeliveredHistoryAndItsAttempts(): void
    {
        $uid = $this->repository->enqueue($this->event(), 'main');
        $claimed = $this->repository->claimByUid($uid, $this->clock->now(), 30);
        self::assertNotNull($claimed);
        self::assertTrue($this->repository->markSuccessful($claimed, false, [], $this->clock->now()));
        $this->repository->recordAttempt(
            $claimed,
            $this->clock->now(),
            $this->clock->now(),
            'accepted',
            202,
            'Accepted.',
        );
        $this->repository->recordAttempt(
            $claimed,
            $this->clock->now(),
            $this->clock->now(),
            'accepted',
            202,
            'Late duplicate worker result.',
        );
        self::assertCount(1, $this->repository->attempts($uid));

        self::assertSame(
            1,
            $this->repository->pruneDeliveredBefore(new DateTimeImmutable('2026-08-19T00:00:00+00:00')),
        );
        self::assertNull($this->repository->findRow($uid));
        self::assertSame([], $this->repository->attempts($uid));
    }

    private function event(): OutgoingEvent
    {
        return new OutgoingEvent(
            eventId: 'event-1',
            type: EventType::Custom,
            title: 'TYPO3 connection test',
            occurredAt: $this->clock->now(),
            siteUrl: 'https://example.com/',
            pageUrls: [],
            environment: 'production',
        );
    }

    private function memoryBackend(): TransientMemoryBackend
    {
        $class = new ReflectionClass(TransientMemoryBackend::class);
        $constructor = $class->getConstructor();
        $arguments = $constructor !== null && $constructor->getNumberOfRequiredParameters() > 0
            ? ['Testing']
            : [];
        $backend = $class->newInstanceArgs($arguments);
        self::assertInstanceOf(TransientMemoryBackend::class, $backend);

        return $backend;
    }
}
