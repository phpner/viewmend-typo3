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

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_string($path)) {
    require __DIR__ . '/dashboard.php';
    return;
}
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !is_string($path)
    || preg_match('#^/api/v1/site-tracker/integrations/[^/]+/events$#', $path) !== 1
) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"message":"not found"}';
    return;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$eventId = is_array($payload) && is_string($payload['event_id'] ?? null)
    ? $payload['event_id']
    : '';
if ($eventId === '') {
    http_response_code(422);
    header('Content-Type: application/json');
    echo '{"message":"invalid event"}';
    return;
}
if (str_contains($eventId, 'permanent')) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo '{"message":"test-only permanent rejection"}';
    return;
}
if (str_contains($eventId, 'retry')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"message":"test-only transient failure"}';
    return;
}

$duplicate = str_contains($eventId, 'duplicate');
$deliveryId = 'e2e-' . substr(hash('sha256', $eventId), 0, 20);
http_response_code($duplicate ? 200 : 202);
header('Content-Type: application/json');
header('X-ViewMend-Delivery: ' . $deliveryId);
echo json_encode([
    'ok' => true,
    'delivery_id' => $deliveryId,
    'event_id' => 'server-' . $eventId,
    'duplicate' => $duplicate,
    'affected_pages' => 1,
    'ignored_urls' => 0,
    'checks_queued' => 1,
    'queue_status' => $duplicate ? 'duplicate' : 'queued',
    'scheduled_for' => null,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
