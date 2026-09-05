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

namespace ViewMend\Typo3SiteTracker\Backend\Dashboard;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

class DashboardHttpTransport
{
    public function __construct(private readonly RequestFactory $requests)
    {
    }

    public function apiBase(): string
    {
        return 'https://viewmend.com/api/v1';
    }

    /** @param array<string, string|int> $query */
    public function request(
        string $integrationId,
        string $path,
        array $query,
        #[\SensitiveParameter] string $token
    ): ResponseInterface {
        return $this->requests->request(
            $this->apiBase() . '/site-tracker/integrations/' . rawurlencode($integrationId) . $path,
            'GET',
            [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'User-Agent' => 'ViewMend-Site-Tracker-TYPO3/1',
                ],
                'connect_timeout' => 3,
                'timeout' => 8,
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'stream' => true,
            ],
        );
    }
}
