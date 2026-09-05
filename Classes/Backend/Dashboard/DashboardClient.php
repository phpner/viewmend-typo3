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

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\Redactor;

final class DashboardClient
{
    public const CACHE_TTL = 300;
    public const RESOURCE_TYPES = ['images', 'javascript', 'css', 'other'];
    private const MAXIMUM_RESPONSE_BYTES = 2097152;

    private readonly FrontendInterface $cache;

    public function __construct(
        private readonly ConfigurationResolver $credentials,
        private readonly DashboardHttpTransport $transport,
        CacheManager $caches,
        private readonly ClockInterface $clock,
        private readonly Redactor $redactor,
    ) {
        $this->cache = $caches->getCache('viewmend_site_tracker');
    }

    /**
     * @return array<string, mixed>
     *   Safe result, never an upstream exception or arbitrary error body.
     */
    public function dashboard(string $pageId = '', string $device = 'desktop', bool $refresh = false): array
    {
        if (($pageId !== '' && !self::validId($pageId)) || !in_array($device, ['desktop', 'mobile'], true)) {
            return $this->failure('invalid_scope', 422);
        }
        $identity = $this->identity();
        if ($identity === null) {
            return $this->failure('configuration_missing');
        }
        $key = $identity . '_' . hash('sha256', $pageId . ':' . $device);
        $observed = $this->cache->get($identity);
        $cached = $this->cache->get($key);
        if (!$refresh && is_array($cached) && (!is_array($observed) || !empty($observed['success']))) {
            return array_replace($this->map($cached), ['cached' => true]);
        }
        $result = $this->request('/dashboard', array_filter(['page_id' => $pageId, 'device' => $device]));
        $data = $this->map($result['data']);
        if ($result['success'] && (!is_array($data['scope'] ?? null) || !is_array($data['summary'] ?? null))) {
            $result = $this->failure('invalid_response');
        }
        $this->cache->set($identity, ['success' => $result['success']], [], self::CACHE_TTL);
        if ($result['success']) {
            $this->cache->remove($identity . '_authentication');
            $this->cache->set($key, $result, [], self::CACHE_TTL);
        } else {
            $this->cache->remove($key);
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     *   An allowlisted resource inventory for the authenticated backend endpoint.
     */
    public function resources(string $runId, string $type, string $device): array
    {
        if (
            !self::validId($runId) || !in_array($type, self::RESOURCE_TYPES, true) || !in_array($device, [
                    'desktop',
                    'mobile',
                ], true)
        ) {
            return $this->failure('invalid_scope', 422);
        }
        $result = $this->request('/runs/' . rawurlencode($runId) . '/resources', [
                'type' => $type,
                'device' => $device,
                'per_page' => 300,
        ]);
        if ($result['success']) {
            $data = $this->map($result['data']);
            if (!is_array($data['items'] ?? null) || !is_array($data['summary'] ?? null)) {
                return $this->failure('invalid_response');
            }
            $identity = $this->identity();
            if ($identity !== null) {
                $this->cache->remove($identity . '_authentication');
            }
            $items = [];
            foreach (array_slice($data['items'], 0, 300) as $row) {
                if (is_array($row)) {
                    $items[] = array_intersect_key($row, array_flip([
                                'url',
                                'mime_type',
                                'status_code',
                                'transferred_bytes',
                                'duration_ms',
                                'third_party',
                                'render_blocking',
                    ]));
                }
            }
            $result['data'] = [
                'items' => $items,
                'summary' => array_intersect_key($data['summary'], array_flip([
                            'requests',
                            'transferred_bytes',
                            'stored_requests',
                            'reported_requests',
                            'truncated',
                ])),
            ];
            // An observed credential failure also invalidates cached overview scopes.
        } elseif (in_array($result['http_status'], [401, 410], true)) {
            $identity = $this->identity();
            if ($identity !== null) {
                $this->cache->set($identity, ['success' => false], [], self::CACHE_TTL);
            }
        }
        return $result;
    }

    /**
     * Reads a recent API authentication failure without a network request.
     */
    public function authenticationFailure(?int $successfulTestAt = null): ?int
    {
        $identity = $this->identity();
        $observation = $identity !== null ? $this->cache->get($identity . '_authentication') : false;
        if (
            !$observation ||
            !is_array($observation) ||
            ($successfulTestAt !== null &&
                $successfulTestAt > $observation['observed_at'])
        ) {
            return null;
        }
        return in_array($observation['status'], [401, 410], true) ? $observation['status'] : null;
    }

    private function identity(): ?string
    {
        $connection = $this->credentials->resolve();
        if (!$connection->isConnectionReady() || !self::validId($connection->integrationId)) {
            return null;
        }
        try {
            return 'dashboard_' . hash(
                'sha256',
                $this->transport->apiBase() . ':' . $connection->integrationId . ':' . $connection->apiToken
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, string|int> $query
     *   Validated request filters.
     *
     * @return array<string, mixed>
     *   Sanitized API result.
     */
    private function request(string $path, array $query): array
    {
        if ($this->identity() === null) {
            return $this->failure('configuration_missing');
        }
        $connection = $this->credentials->resolve();
        $token = (string) $connection->apiToken;
        try {
            $response = $this->transport->request($connection->integrationId, $path, $query, $token);
            $body = $response->getBody();
            try {
                $contents = '';
                while (!$body->eof() && strlen($contents) <= self::MAXIMUM_RESPONSE_BYTES) {
                    $chunk = $body->read(min(65536, self::MAXIMUM_RESPONSE_BYTES + 1 - strlen($contents)));
                    if ($chunk === '') {
                        break;
                    }
                    $contents .= $chunk;
                }
            } finally {
                $body->close();
            }
        } catch (\Throwable) {
            return $this->failure('network_error');
        }
        $status = $response->getStatusCode();
        $identity = $this->identity();
        if (in_array($status, [401, 410], true)) {
            $this->cache->set(
                $identity . '_authentication',
                ['status' =>
                    $status,
                    'observed_at' =>
                    $this->clock->now()->getTimestamp()],
                [],
                self::CACHE_TTL
            );
        }
        if ($status !== 200) {
            return $this->failure('http_error', $status);
        }
        $payload = strlen($contents) <= self::MAXIMUM_RESPONSE_BYTES ? json_decode($contents, true, 32) : null;
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return $this->failure('invalid_response');
        }

        $meta = $this->map($payload['meta'] ?? null);
        $data = $this->sanitize($payload['data'], $token);
        // URLs returned for API resources are never used as network destinations.
        if (is_array($data['transfer'] ?? null)) {
            unset($data['transfer']['resource_endpoint']);
        }
        return [
            'success' => true,
            'sample' => ($meta['sample'] ?? false) === true,
            'http_status' => 200,
            'data' => $data,
            'generated_at' =>
            is_string($meta['generated_at'] ?? null) ? $this->redactor->redact(
                $meta['generated_at'],
                [$token]
            ) : null,
            'cached' => false,
            'error_code' => null,
            'message' => null,
        ];
    }

    /**
     * @param array<mixed> $data
     *   API data to sanitize before caching or rendering.
     *
     * @return array<mixed>
     *   Recursively redacted scalar data.
     */
    private function sanitize(array $data, string $token): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (
                is_string($key) &&
                preg_match(
                    '/token|secret|cookie|header|authorization|collector|remote_ip/i',
                    $key
                )
            ) {
                continue;
            }
            if (is_string($key)) {
                $key = $this->redactor->redact($key, [$token]);
            }
            $clean[$key] = is_array($value)
            ? $this->sanitize(
                $value,
                $token
            )
            : (is_string($value)
                ? $this->redactor->redact(
                    $value,
                    [$token]
                )
                : $value);
        }
        return $clean;
    }

    /**
     * @return array<string, mixed>
     *   A local failure message without upstream response content.
     */
    private function failure(string $code, ?int $status = null): array
    {
        $message = match (true) {
            $code === 'configuration_missing' => 'Complete the connection to load ViewMend statistics.',
            $status === 401 =>
            'ViewMend rejected the saved credentials. Check the integration ID and API token.',
            $status === 410 =>
            'This ViewMend connection is disabled. Enable it in ViewMend or configure another integration.',
            $status === 404 =>
            'This page or check is no longer available for the connected project. Select another page.',
            $status === 422 => 'The selected page, device, or resource type is invalid.',
            $status === 429 => 'ViewMend is receiving too many requests. Try again in a few minutes.',
            default => 'ViewMend statistics are temporarily unavailable. Try again in a few minutes. ' .
            'Local event delivery is managed separately below.',
        };
        return [
            'success' => false,
            'http_status' => $status,
            'data' => null,
            'generated_at' => null,
            'cached' => false,
            'error_code' => $code,
            'message' => (string) $message,
        ];
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id) === 1;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }
}
