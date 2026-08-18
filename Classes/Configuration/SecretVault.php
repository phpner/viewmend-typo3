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
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class SecretVault
{
    private const CIPHER = 'aes-256-gcm';

    public function encrypt(#[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new RuntimeException('An empty API token cannot be encrypted.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'viewmend_site_tracker',
            16,
        );
        if ($ciphertext === false) {
            throw new RuntimeException('The API token could not be encrypted.');
        }

        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(#[\SensitiveParameter] string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1:')) {
            throw new RuntimeException('The stored API token uses an unsupported format.');
        }
        $payload = base64_decode(substr($encoded, 3), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('The stored API token is damaged.');
        }
        $secret = openssl_decrypt(
            substr($payload, 28),
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
            'viewmend_site_tracker',
        );
        if ($secret === false || $secret === '') {
            throw new RuntimeException('The stored API token cannot be decrypted. Check the TYPO3 encryption key.');
        }

        return $secret;
    }

    private function key(): string
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $system = is_array($configuration) && is_array($configuration['SYS'] ?? null)
            ? $configuration['SYS']
            : [];
        $encryptionKey = ScalarValue::string($system['encryptionKey'] ?? null);
        if (strlen($encryptionKey) < 32) {
            throw new RuntimeException('TYPO3 SYS/encryptionKey must be configured before storing an API token.');
        }

        return hash('sha256', 'viewmend_site_tracker|' . $encryptionKey, true);
    }
}
