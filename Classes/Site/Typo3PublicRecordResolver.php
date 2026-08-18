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

namespace ViewMend\Typo3SiteTracker\Site;

use JsonException;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use ViewMend\Typo3SiteTracker\Configuration\TrackerConfiguration;
use ViewMend\Typo3SiteTracker\Persistence\ConnectionProviderInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class Typo3PublicRecordResolver implements PublicRecordResolverInterface
{
    private const SUPPORTED_TABLES = ['pages', 'tt_content'];

    private const FINGERPRINT_EXCLUDED_FIELDS = [
        'uid',
        'pid',
        'tstamp',
        'crdate',
        'cruser_id',
        'deleted',
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'sys_language_uid',
        'l10n_parent',
        'l10n_source',
        't3ver_oid',
        't3ver_wsid',
        't3ver_state',
        't3ver_stage',
        't3ver_count',
        't3ver_tstamp',
        't3ver_move_id',
        'editlock',
        'perms_userid',
        'perms_groupid',
        'perms_user',
        'perms_group',
        'perms_everybody',
    ];

    public function __construct(
        private ConnectionProviderInterface $connections,
        private SiteFinder $siteFinder,
        private PublicEligibilityPolicy $eligibility,
        private CanonicalUrlPolicy $urls,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(string $table, int $uid, TrackerConfiguration $configuration): ?ResolvedPublicRecord
    {
        if (!in_array($table, self::SUPPORTED_TABLES, true) || $uid <= 0) {
            return null;
        }
        $row = $this->row($table, $uid);
        if (
            $row === null
            || ScalarValue::int($row['deleted'] ?? null) !== 0
            || $this->eligibility->isWorkspaceRecord($row)
        ) {
            return null;
        }
        $languageId = max(0, ScalarValue::int($row['sys_language_uid'] ?? null));
        $pageId = $table === 'pages'
            ? (ScalarValue::int($row['l10n_parent'] ?? null) ?: ScalarValue::int($row['uid'] ?? null))
            : ScalarValue::int($row['pid'] ?? null);
        if ($pageId <= 0) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            if ($site->getIdentifier() !== $configuration->siteIdentifier) {
                return null;
            }
            $siteLanguage = $site->getLanguageById($languageId);
            $siteUrl = rtrim((string) $site->getBase(), '/') . '/';
            if (!$this->urls->basesMatch($siteUrl, $configuration->siteUrl)) {
                return null;
            }
            $pageUrl = (string) $site->getRouter()->generateUri(
                $pageId,
                ['_language' => $siteLanguage],
                '',
                RouterInterface::ABSOLUTE_URL,
            );
        } catch (Throwable) {
            return null;
        }
        if (!$this->urls->isPublicHttpUrl($pageUrl)) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        $pageRow = $this->row('pages', $pageId);
        if ($pageRow === null) {
            return null;
        }
        $public = $this->pageTreeIsPublic($pageRow, $configuration, $now);
        $nextTransitionAt = $this->eligibility->nextTransition($row, $now);

        if ($table === 'pages') {
            $public = $public
                && in_array(ScalarValue::int($row['doktype'] ?? null), $configuration->allowedPageTypes, true)
                && $this->eligibility->isPublic($row, $now);
        } else {
            $public = $public
                && in_array(
                    ScalarValue::string($row['CType'] ?? null),
                    $configuration->allowedContentTypes,
                    true,
                )
                && $this->eligibility->isPublic($row, $now);
        }

        return new ResolvedPublicRecord(
            table: $table,
            uid: ScalarValue::int($row['uid'] ?? null),
            languageId: $languageId,
            siteIdentifier: $site->getIdentifier(),
            siteUrl: $siteUrl,
            pageUrl: $pageUrl,
            public: $public,
            fingerprint: $this->fingerprint($row),
            nextTransitionAt: $nextTransitionAt,
        );
    }

    public function exists(string $table, int $uid): bool
    {
        if (!in_array($table, self::SUPPORTED_TABLES, true)) {
            return false;
        }
        $row = $this->row($table, $uid);

        return $row !== null && ScalarValue::int($row['deleted'] ?? null) === 0;
    }

    /** @param array<string, mixed> $page */
    private function pageTreeIsPublic(array $page, TrackerConfiguration $configuration, int $now): bool
    {
        $seen = [];
        while (true) {
            $uid = ScalarValue::int($page['uid'] ?? null);
            if ($uid <= 0 || isset($seen[$uid]) || !$this->eligibility->isPublic($page, $now)) {
                return false;
            }
            $seen[$uid] = true;
            if (
                !in_array(
                    ScalarValue::int($page['doktype'] ?? null),
                    $configuration->allowedPageTypes,
                    true,
                )
            ) {
                return false;
            }
            $parentId = ScalarValue::int($page['pid'] ?? null);
            if ($parentId === 0) {
                return true;
            }
            $page = $this->row('pages', $parentId) ?? [];
        }
    }

    /** @param array<string, mixed> $row */
    private function fingerprint(array $row): string
    {
        $data = array_diff_key($row, array_flip(self::FINGERPRINT_EXCLUDED_FIELDS));
        ksort($data);
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            $encoded = serialize(array_map(static fn (mixed $value): string => get_debug_type($value), $data));
        }

        return hash('sha256', $encoded);
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connections->forTable($table)->createQueryBuilder();
        // Lifecycle classification needs the raw hidden/deleted flags. TYPO3's
        // default restrictions would otherwise make an unpublish look like a deletion.
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where('uid = :uid')
            ->setParameter('uid', $uid, Connection::PARAM_INT)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
