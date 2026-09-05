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

header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer local-test-token-not-for-production') {
    http_response_code(401);
    echo '{"message":"Unauthenticated"}';
    return;
}
$fixture = json_decode((string) file_get_contents(__DIR__ . '/dashboard.json'), true, 512, JSON_THROW_ON_ERROR);
$data = $fixture['data'];
if (str_ends_with($path, '/dashboard')) {
    $pageId = $_GET['page_id'] ?? $data['scope']['page']['id'];
    $page = array_values(array_filter(
        $data['scope']['available_pages'],
        static fn (array $page): bool =>
        $page['id'] === $pageId
    ))[0] ?? null;
    if ($page === null) {
        http_response_code(404);
        echo '{"message":"Page not found"}';
        return;
    }
    $device = ($_GET['device'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
    $fixture['data']['scope']['page'] = $page;
    $fixture['data']['scope']['device'] = $device;
    foreach ($fixture['data']['links'] as &$link) {
        $link = str_replace([$data['scope']['page']['id'], 'device=desktop'], [$pageId, 'device=' . $device], $link);
    }
    unset($link);
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
    return;
}
if (
    !str_contains(
        $path,
        $data['transfer']['run_id']
    ) ||
    !in_array(
        $_GET['type'] ?? '',
        ['images',
        'javascript',
        'css',
        'other'],
        true
    )
) {
    http_response_code(404);
    echo '{"message":"Not found"}';
    return;
}
$items = [];
foreach (['main', 'checkout', 'navigation'] as $name) {
    $extension = ['images' => 'webp', 'javascript' => 'js', 'css' => 'css', 'other' => 'woff2'][$_GET['type']];
    $items[] = ['url' =>
        'https://example.com/_assets/' . $name . '.' . $extension,
         'mime_type' =>
        'application/octet-stream',
         'status_code' =>
        200,
         'transferred_bytes' =>
        12000,
         'duration_ms' =>
        24,
         'third_party' =>
        false,
         'render_blocking' =>
        $_GET['type'] === 'css'];
}
echo json_encode(
    ['data' =>
    ['items' =>
    $items,
     'summary' =>
    ['requests' =>
    count($items),
     'transferred_bytes' =>
    36000,
     'stored_requests' =>
    count($items),
     'reported_requests' =>
    count($items),
     'truncated' =>
    false]]],
    JSON_THROW_ON_ERROR
);
