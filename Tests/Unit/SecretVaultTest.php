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
use RuntimeException;
use ViewMend\Typo3SiteTracker\Configuration\SecretVault;

final class SecretVaultTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
    }

    public function testEncryptsWithAuthenticatedRandomNoncesAndDecrypts(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 64);
        $vault = new SecretVault();

        $first = $vault->encrypt('private-token');
        $second = $vault->encrypt('private-token');

        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('private-token', $first);
        self::assertSame('private-token', $vault->decrypt($first));
    }

    public function testRejectsStorageWithoutTypo3EncryptionKey(): void
    {
        $this->expectException(RuntimeException::class);
        (new SecretVault())->encrypt('private-token');
    }
}
