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

namespace ViewMend\Typo3SiteTracker\Tests\MariaDb;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver;
use PHPUnit\Framework\TestCase;
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

final class MariaDbDeliveryRepositoryTest extends TestCase
{
    private Connection $connection;
    private FrozenClock $clock;
    private DoctrineDeliveryRepository $repository;

    protected function setUp(): void
    {
        $host = getenv('VIEWMEND_TEST_DB_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('The isolated MariaDB test environment is not configured.');
        }
        $database = $this->environment('VIEWMEND_TEST_DB_NAME', 'viewmend_test');
        if (!str_ends_with($database, '_test')) {
            self::fail('The MariaDB suite only runs against a database name ending in _test.');
        }

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][PackageDependentCacheIdentifier::class]['className'] =
            StaticPackageDependentCacheIdentifier::class;
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        foreach (['runtime', 'database_schema'] as $identifier) {
            if (!$cacheManager->hasCache($identifier)) {
                $cacheManager->registerCache(new VariableFrontend($identifier, $this->memoryBackend()));
            }
        }

        $this->connection = new UnrestrictedTestConnection([
            'driver' => 'pdo_mysql',
            'host' => $host,
            'port' => (int) $this->environment('VIEWMEND_TEST_DB_PORT', '3306'),
            'dbname' => $database,
            'user' => $this->environment('VIEWMEND_TEST_DB_USER', 'viewmend'),
            'password' => $this->environment('VIEWMEND_TEST_DB_PASSWORD', 'viewmend'),
            'charset' => 'utf8mb4',
        ], new Driver(), new Configuration());
        $this->resetSchema();
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

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropSchema();
        }
    }

    public function testMariaDbLeaseDeduplicationAndTerminalRetention(): void
    {
        $event = new OutgoingEvent(
            eventId: 'mariadb-stable-event',
            type: EventType::Deployment,
            title: 'TYPO3 deployment',
            occurredAt: $this->clock->now(),
            siteUrl: 'https://example.com/',
            pageUrls: [],
            environment: 'production',
        );
        $uid = $this->repository->enqueue($event, 'main');
        self::assertSame($uid, $this->repository->enqueue($event, 'main'));
        $claimed = $this->repository->claimNext($this->clock->now(), 60);
        self::assertNotNull($claimed);
        self::assertNull($this->repository->claimNext($this->clock->now(), 60));
        self::assertTrue($this->repository->markSuccessful($claimed, true, [], $this->clock->now()));
        self::assertSame(1, $this->repository->counts('main')[DeliveryStatus::Duplicate->value]);
        self::assertSame(
            1,
            $this->repository->pruneDeliveredBefore(new DateTimeImmutable('2026-08-19T00:00:00+00:00')),
        );
    }

    public function testSearchEscapesWildcardsAndPaginatesWithinTheSite(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->repository->enqueue(new OutgoingEvent(
                eventId: 'search-' . $index,
                type: EventType::Custom,
                title: 'Price 50%_off',
                occurredAt: $this->clock->now(),
                siteUrl: 'https://example.com/',
                pageUrls: ['https://example.com/catalog/item'],
                environment: 'production',
            ), $index === 2 ? 'secondary' : 'main');
        }
        self::assertSame(2, $this->repository->countRecent(null, 'main', '%_'));
        self::assertSame(0, $this->repository->countRecent(null, 'main', 'missing'));
        self::assertCount(1, $this->repository->findRecent(null, 'main', 1, 1, 'catalog/item'));
        self::assertSame(1, $this->repository->countRecent(null, 'secondary'));
        self::assertSame(0, $this->repository->countRecent(DeliveryStatus::Accepted, 'main'));
    }

    private function resetSchema(): void
    {
        $this->dropSchema();
        $this->connection->executeStatement('CREATE TABLE tx_viewmendsitetracker_delivery (
            uid INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(160) NOT NULL,
            site_identifier VARCHAR(255) NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            title VARCHAR(140) NOT NULL,
            payload TEXT NOT NULL,
            status VARCHAR(40) NOT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lease_token VARCHAR(64) NOT NULL DEFAULT \'\',
            locked_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
            api_delivery_id VARCHAR(255) NOT NULL DEFAULT \'\',
            api_event_id VARCHAR(255) NOT NULL DEFAULT \'\',
            queue_status VARCHAR(120) NOT NULL DEFAULT \'\',
            response_summary VARCHAR(1000) NOT NULL DEFAULT \'\',
            last_http_status INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(1000) NOT NULL DEFAULT \'\',
            created_at BIGINT UNSIGNED NOT NULL,
            updated_at BIGINT UNSIGNED NOT NULL,
            delivered_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            KEY queue_lookup (status, next_attempt_at),
            KEY site_status (site_identifier, status),
            UNIQUE KEY event_scope (site_identifier, event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->connection->executeStatement('CREATE TABLE tx_viewmendsitetracker_attempt (
            uid INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            delivery_uid INT UNSIGNED NOT NULL,
            attempt_number INT UNSIGNED NOT NULL,
            started_at BIGINT UNSIGNED NOT NULL,
            finished_at BIGINT UNSIGNED NOT NULL,
            outcome VARCHAR(40) NOT NULL,
            http_status INT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(1000) NOT NULL DEFAULT \'\',
            UNIQUE KEY delivery_attempt (delivery_uid, attempt_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS tx_viewmendsitetracker_attempt');
        $this->connection->executeStatement('DROP TABLE IF EXISTS tx_viewmendsitetracker_delivery');
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function memoryBackend(): TransientMemoryBackend
    {
        $class = new \ReflectionClass(TransientMemoryBackend::class);
        $constructor = $class->getConstructor();
        $arguments = $constructor !== null && $constructor->getNumberOfRequiredParameters() > 0
            ? ['Testing']
            : [];
        $backend = $class->newInstanceArgs($arguments);
        self::assertInstanceOf(TransientMemoryBackend::class, $backend);

        return $backend;
    }
}
