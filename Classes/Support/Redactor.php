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

namespace ViewMend\Typo3SiteTracker\Support;

final readonly class Redactor
{
    private const MAX_MESSAGE_LENGTH = 1000;

    /** @param list<string> $secrets */
    public function redact(string $message, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        $patterns = [
            '/(authorization\s*[:=]\s*bearer\s+)[^\s,;]+/i',
            '/((?:api[_-]?token|access[_-]?token|password|secret)\s*[:=]\s*)[^\s,;]+/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{8,}=*/i',
        ];
        $message = preg_replace($patterns, '$1[REDACTED]', $message) ?? 'Sensitive error details were redacted.';

        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '...';
        }

        return $message;
    }
}
