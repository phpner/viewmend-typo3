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

use Doctrine\DBAL\ParameterType;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class DoctrineObservationRepository implements ObservationRepositoryInterface
{
    private const TABLE = 'tx_viewmendsitetracker_observation';

    public function __construct(private ConnectionProviderInterface $connections)
    {
    }

    public function find(string $siteIdentifier, string $table, int $uid, int $languageId): ?Observation
    {
        $row = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('site_identifier = :site')
            ->andWhere('record_table = :table')
            ->andWhere('record_uid = :uid')
            ->andWhere('language_id = :language')
            ->setParameter('site', $siteIdentifier)
            ->setParameter('table', $table)
            ->setParameter('uid', $uid, ParameterType::INTEGER)
            ->setParameter('language', $languageId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function save(Observation $observation): void
    {
        $connection = $this->connections->forTable(self::TABLE);
        $existing = $this->find(
            $observation->siteIdentifier,
            $observation->recordTable,
            $observation->recordUid,
            $observation->languageId,
        );
        $values = [
            'site_url' => $observation->siteUrl,
            'page_url' => $observation->pageUrl,
            'public_state' => $observation->public ? 1 : 0,
            'fingerprint' => $observation->fingerprint,
            'revision' => $observation->revision,
            'next_transition_at' => $observation->nextTransitionAt,
            'last_seen_at' => $observation->lastSeenAt,
        ];
        if ($existing === null) {
            $connection->insert(self::TABLE, array_merge($values, [
                'site_identifier' => $observation->siteIdentifier,
                'record_table' => $observation->recordTable,
                'record_uid' => $observation->recordUid,
                'language_id' => $observation->languageId,
            ]));
            return;
        }
        $connection->update(self::TABLE, $values, [
            'site_identifier' => $observation->siteIdentifier,
            'record_table' => $observation->recordTable,
            'record_uid' => $observation->recordUid,
            'language_id' => $observation->languageId,
        ]);
    }

    public function findDue(int $timestamp, int $limit): array
    {
        $rows = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('next_transition_at > 0')
            ->andWhere('next_transition_at <= :now')
            ->setParameter('now', $timestamp, ParameterType::INTEGER)
            ->orderBy('next_transition_at', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByRecord(string $table, int $uid): array
    {
        $rows = $this->connections->forTable(self::TABLE)->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('record_table = :table')
            ->andWhere('record_uid = :uid')
            ->setParameter('table', $table)
            ->setParameter('uid', $uid, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Observation
    {
        return new Observation(
            siteIdentifier: ScalarValue::string($row['site_identifier'] ?? null),
            recordTable: ScalarValue::string($row['record_table'] ?? null),
            recordUid: ScalarValue::int($row['record_uid'] ?? null),
            languageId: ScalarValue::int($row['language_id'] ?? null),
            siteUrl: ScalarValue::string($row['site_url'] ?? null),
            pageUrl: ScalarValue::string($row['page_url'] ?? null),
            public: (bool) $row['public_state'],
            fingerprint: ScalarValue::string($row['fingerprint'] ?? null),
            revision: ScalarValue::int($row['revision'] ?? null),
            nextTransitionAt: ScalarValue::int($row['next_transition_at'] ?? null),
            lastSeenAt: ScalarValue::int($row['last_seen_at'] ?? null),
        );
    }
}
