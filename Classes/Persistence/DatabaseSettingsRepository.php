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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use ViewMend\Typo3SiteTracker\Configuration\SettingsRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;

final readonly class DatabaseSettingsRepository implements SettingsRepositoryInterface
{
    private const TABLE = 'tx_viewmendsitetracker_settings';

    public function __construct(
        private ConnectionProviderInterface $connections,
        private ClockInterface $clock,
    ) {
    }

    public function getRaw(): array
    {
        $connection = $this->connections->forTable(self::TABLE);
        $row = $connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('uid = :uid')
            ->setParameter('uid', 1, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    public function save(array $values): void
    {
        $now = $this->clock->now()->getTimestamp();
        $connection = $this->connections->forTable(self::TABLE);
        if ($this->getRaw() === []) {
            try {
                $connection->insert(self::TABLE, array_merge([
                    'uid' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $values));
                return;
            } catch (UniqueConstraintViolationException) {
                // Another backend request or worker initialized the singleton.
            }
        }
        $connection->update(self::TABLE, array_merge($values, ['updated_at' => $now]), ['uid' => 1]);
    }

    /** @param array<string, int|string|null> $values */
    public function updateOperationalState(array $values): void
    {
        $this->save($values);
    }
}
