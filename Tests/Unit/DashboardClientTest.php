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

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\DashboardClient;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\DashboardHttpTransport;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\SecretVault;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Tests\Support\FrozenClock;
use ViewMend\Typo3SiteTracker\Tests\Support\InMemorySettingsRepository;

final class DashboardClientTest extends TestCase
{
    private InMemorySettingsRepository $settings;
    private SecretVault $vault;
    private CacheManager $cache;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('t', 64);
        $this->vault = new SecretVault();
        $this->settings = new InMemorySettingsRepository([
            'integration_id' => '11111111-1111-4111-8111-111111111111',
            'site_identifier' => 'main', 'site_url' => 'https://example.com/',
            'token_ciphertext' => $this->vault->encrypt('private-dashboard-token'),
        ]);
        $backend = (new ReflectionClass(TransientMemoryBackend::class))->newInstanceWithoutConstructor();
        $frontend = new VariableFrontend('viewmend_site_tracker', $backend);
        $this->cache = $this->createMock(CacheManager::class);
        $this->cache->method('getCache')->willReturn($frontend);
    }

    public function testCachesByDeviceAndTokenAndPreservesSampleMetadata(): void
    {
        $http = $this->createMock(DashboardHttpTransport::class);
        $http->method('apiBase')->willReturn('https://viewmend.com/api/v1');
        $http->expects(self::exactly(3))->method('request')->willReturnCallback(
            fn (): Response => new Response(200, [], $this->fixture()),
        );
        $client = $this->client($http);
        self::assertFalse($client->dashboard()['cached']);
        self::assertTrue($client->dashboard()['cached']);
        self::assertTrue($client->dashboard()['sample']);
        self::assertFalse($client->dashboard('', 'mobile')['cached']);
        $this->settings->save(['token_ciphertext' => $this->vault->encrypt('rotated-token')]);
        self::assertFalse($client->dashboard()['cached']);
    }

    public function testResourceAuthenticationFailureInvalidatesCachedDashboard(): void
    {
        $http = $this->createMock(DashboardHttpTransport::class);
        $http->method('apiBase')->willReturn('https://viewmend.com/api/v1');
        $http->expects(self::exactly(4))->method('request')->willReturnOnConsecutiveCalls(
            new Response(200, [], $this->fixture()),
            new Response(401, [], 'private-dashboard-token'),
            new Response(200, [], '{"data":{}}'),
            new Response(200, [], $this->fixture()),
        );
        $client = $this->client($http);
        self::assertTrue($client->dashboard()['success']);
        $failure = $client->resources('00000000-0000-4000-8000-000000000301', 'images', 'desktop');
        self::assertFalse($failure['success']);
        self::assertStringNotContainsString('private-dashboard-token', json_encode($failure));
        self::assertSame(401, $client->authenticationFailure());
        self::assertFalse($client->dashboard()['success']);
        self::assertSame(401, $client->authenticationFailure());
        self::assertTrue($client->dashboard()['success']);
        self::assertNull($client->authenticationFailure());
    }

    public function testInvalidScopeNeverMakesAnHttpRequest(): void
    {
        $http = $this->createMock(DashboardHttpTransport::class);
        $http->expects(self::never())->method('request');
        $client = $this->client($http);
        self::assertFalse($client->dashboard('https://example.com/')['success']);
        self::assertFalse($client->dashboard('', 'tablet')['success']);
        self::assertFalse($client->resources('../private', 'images', 'desktop')['success']);
    }

    public function testRejectsOversizedAndNonJsonResponses(): void
    {
        $http = $this->createMock(DashboardHttpTransport::class);
        $http->method('apiBase')->willReturn('https://viewmend.com/api/v1');
        $http->method('request')->willReturnOnConsecutiveCalls(
            new Response(200, [], str_repeat('x', 2097153)),
            new Response(200, [], '<html>Upstream error</html>'),
        );
        $client = $this->client($http);
        self::assertSame('invalid_response', $client->dashboard()['error_code']);
        self::assertSame('invalid_response', $client->dashboard()['error_code']);
    }

    public function testRedactsNestedSecretsAndDiscardsRemoteResourceEndpoint(): void
    {
        $payload = json_decode($this->fixture(), true);
        $payload['data']['secret'] = 'sensitive';
        $payload['data']['scope']['page']['url'] = 'https://example.com/private-dashboard-token';
        $payload['data']['transfer']['resource_endpoint'] = 'http://127.0.0.1/private';
        $http = $this->createMock(DashboardHttpTransport::class);
        $http->method('apiBase')->willReturn('https://viewmend.com/api/v1');
        $http->method('request')->willReturn(new Response(200, [], json_encode($payload)));
        $data = $this->client($http)->dashboard()['data'];
        self::assertArrayNotHasKey('secret', $data);
        self::assertArrayNotHasKey('resource_endpoint', $data['transfer']);
        self::assertStringNotContainsString('private-dashboard-token', json_encode($data));
    }

    private function client(DashboardHttpTransport $http): DashboardClient
    {
        return new DashboardClient(
            new ConfigurationResolver($this->settings, $this->vault, new Redactor()),
            $http,
            $this->cache,
            new FrozenClock(new DateTimeImmutable('2026-09-05T12:00:00Z')),
            new Redactor(),
        );
    }

    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../E2E/FakeApi/dashboard.json');
    }
}
