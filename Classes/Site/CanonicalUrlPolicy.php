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

final readonly class CanonicalUrlPolicy
{
    public function basesMatch(string $resolved, string $configured): bool
    {
        $resolvedBase = $this->normalizeBase($resolved);

        return $resolvedBase !== null && $resolvedBase === $this->normalizeBase($configured);
    }

    public function isPublicHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    public function belongsToBase(string $url, string $base): bool
    {
        if (!$this->isPublicHttpUrl($url)) {
            return false;
        }
        $normalizedBase = $this->normalizeBase($base);
        $urlParts = parse_url($url);
        if ($normalizedBase === null || !is_array($urlParts)) {
            return false;
        }
        $urlOrigin = $this->origin($urlParts);
        $baseParts = parse_url($normalizedBase);
        if ($urlOrigin === null || !is_array($baseParts) || $urlOrigin !== $this->origin($baseParts)) {
            return false;
        }
        $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');
        $urlPath = rtrim((string) ($urlParts['path'] ?? ''), '/');

        return $basePath === '' || $urlPath === $basePath || str_starts_with($urlPath, $basePath . '/');
    }

    private function normalizeBase(string $url): ?string
    {
        if (!$this->isPublicHttpUrl($url)) {
            return null;
        }
        $parts = parse_url(trim($url));
        if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $origin = $this->origin($parts);
        if ($origin === null) {
            return null;
        }

        return $origin . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /** @param array<string, int|string> $parts */
    private function origin(array $parts): ?string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $portSuffix = $port !== null && !$defaultPort
            ? ':' . $port
            : '';

        return $scheme . '://' . $host . $portSuffix;
    }
}
