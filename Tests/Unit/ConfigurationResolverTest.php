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

namespace ViewMend\Typo3SiteTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\SecretVault;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Tests\Support\InMemorySettingsRepository;

final class ConfigurationResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        $variables = [
            'VIEWMEND_API_TOKEN',
            'VIEWMEND_INTEGRATION_ID',
            'VIEWMEND_SITE_IDENTIFIER',
            'VIEWMEND_SITE_URL',
            'VIEWMEND_ENVIRONMENT',
        ];
        foreach ($variables as $name) {
            putenv($name);
        }
    }

    public function testEnvironmentOverridesEncryptedDatabaseSecret(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('x', 64);
        $vault = new SecretVault();
        $settings = new InMemorySettingsRepository([
            'enabled' => 1,
            'integration_id' => 'database-id',
            'site_identifier' => 'main',
            'site_url' => 'https://example.com/',
            'environment' => 'production',
            'token_ciphertext' => $vault->encrypt('stored-token'),
        ]);
        putenv('VIEWMEND_API_TOKEN=environment-token');
        putenv('VIEWMEND_INTEGRATION_ID=environment-id');

        $configuration = (new ConfigurationResolver($settings, $vault, new Redactor()))->resolve();
        self::assertSame('environment-token', $configuration->apiToken);
        self::assertSame('environment-id', $configuration->integrationId);
        self::assertSame('environment', $configuration->tokenSource);
        self::assertTrue($configuration->isConnectionReady());
        self::assertTrue($configuration->isReady());
    }

    public function testConnectionCanBeReadyBeforeEventCaptureIsEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('z', 64);
        $vault = new SecretVault();
        $settings = new InMemorySettingsRepository([
            'enabled' => 0,
            'integration_id' => 'integration-id',
            'site_identifier' => 'main',
            'site_url' => 'https://example.com/',
            'environment' => 'production',
            'token_ciphertext' => $vault->encrypt('stored-token'),
        ]);

        $configuration = (new ConfigurationResolver($settings, $vault, new Redactor()))->resolve();

        self::assertTrue($configuration->isConnectionReady());
        self::assertFalse($configuration->isReady());
    }

    public function testEncryptedTokenNeverAppearsInCiphertext(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('y', 64);
        $vault = new SecretVault();
        $encrypted = $vault->encrypt('private-token');
        self::assertStringNotContainsString('private-token', $encrypted);
        self::assertSame('private-token', $vault->decrypt($encrypted));
    }
}
