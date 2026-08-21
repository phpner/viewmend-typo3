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

namespace ViewMend\Typo3SiteTracker\Configuration;

use RuntimeException;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class ConfigurationResolver
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private SecretVault $vault,
        private Redactor $redactor,
    ) {
    }

    public function resolve(): TrackerConfiguration
    {
        $raw = $this->settings->getRaw();
        $tokenSource = 'none';
        $token = $this->environment('VIEWMEND_API_TOKEN');
        if ($token !== null) {
            $tokenSource = 'environment';
        } elseif (is_string($raw['token_ciphertext'] ?? null) && $raw['token_ciphertext'] !== '') {
            try {
                $token = $this->vault->decrypt($raw['token_ciphertext']);
                $tokenSource = 'encrypted_database';
            } catch (RuntimeException $exception) {
                $this->settings->updateOperationalState([
                    'last_error' => $this->redactor->redact($exception->getMessage()),
                ]);
            }
        }

        return new TrackerConfiguration(
            enabled: (bool) ($raw['enabled'] ?? false),
            integrationId: $this->environment('VIEWMEND_INTEGRATION_ID')
                ?? ScalarValue::string($raw['integration_id'] ?? null),
            siteIdentifier: $this->environment('VIEWMEND_SITE_IDENTIFIER')
                ?? ScalarValue::string($raw['site_identifier'] ?? null),
            siteUrl: $this->environment('VIEWMEND_SITE_URL')
                ?? ScalarValue::string($raw['site_url'] ?? null),
            environment: $this->environment('VIEWMEND_ENVIRONMENT')
                ?? ScalarValue::string($raw['environment'] ?? null, 'production'),
            apiToken: $token,
            tokenSource: $tokenSource,
            recordPages: (bool) ($raw['record_pages'] ?? true),
            recordContent: (bool) ($raw['record_content'] ?? true),
            recordPublish: (bool) ($raw['record_publish'] ?? true),
            recordUpdate: (bool) ($raw['record_update'] ?? true),
            recordUnpublish: (bool) ($raw['record_unpublish'] ?? true),
            recordDelete: (bool) ($raw['record_delete'] ?? true),
            recordCacheFlush: (bool) ($raw['record_cache_flush'] ?? false),
            retentionDays: max(1, min(365, ScalarValue::int($raw['retention_days'] ?? null, 30))),
        );
    }

    private function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
