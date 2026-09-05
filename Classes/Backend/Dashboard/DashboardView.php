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

use TYPO3\CMS\Backend\Routing\UriBuilder;

final class DashboardView
{
    public function __construct(private readonly UriBuilder $uris)
    {
    }

    /**
     * @param array<string, mixed> $result
     *   The dashboard client's safe response.
     *
     * @return array<string, mixed>
     *   Template view model.
     */
    public function build(array $result): array
    {
        $data = $this->map($result['data'] ?? null);
        $scope = $this->map($data['scope'] ?? null);
        $summary = $this->map($data['summary'] ?? null);
        $page = $this->map($scope['page'] ?? null);
        $latest = $this->map($data['latest_check'] ?? null);
        $device = ($scope['device'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
        $view = [
            'success' => (bool) $result['success'],
            'sample' => !empty($result['sample']),
            'message' => $result['message'],
            'cached' => $result['cached'],
            'generated' => $this->date($result['generated_at']),
            'has_page' => $page !== [],
            'page' => $page,
            'page_label' => $this->pageLabel($this->text($page['url'] ?? null)),
            'device' => $device,
            'pages' => [],
            'summary' => [],
            'links' => [],
            'issues' => [],
            'critical' => 0,
            'warning' => 0,
            'latest' => $latest,
            'latest_date' => $this->date($latest['finished_at'] ?? null),
            'partial' => ($latest['status'] ?? '') === 'partial_completed',
            'tracker_url' => 'https://viewmend.com/app/tracker',
        ];
        foreach ($this->rows($scope['available_pages'] ?? null) as $available) {
            if (DashboardClient::validId($this->text($available['id'] ?? null))) {
                $view['pages'][] = [
                    'id' => $available['id'],
                    'label' => $this->pageLabel($this->text($available['url'] ?? null)),
                ];
            }
        }
        foreach (['health_score', 'tracked_pages', 'checked_pages', 'open_issues', 'critical_issues'] as $key) {
            $view['summary'][$key] = $this->value($summary[$key] ?? null);
        }
        $view['summary']['delta'] = $this->delta($summary['health_score_delta'] ?? null);
        $view['health_percent'] = max(0, min(100, $this->number($summary['health_score'] ?? null) ?? 0));
        $view['health_known'] = $this->number($summary['health_score'] ?? null) !== null;
        $view['state'] = match (true) {
            !$result['success'] => 'Statistics unavailable',
            $page === [] => 'No tracked pages',
            $latest === [] => 'Awaiting first check',
            ($this->number($summary['open_issues'] ?? null) ?? 0) > 0 => 'Needs attention',
            ($latest['status'] ?? '') === 'partial_completed' => 'Partial check',
            default => 'Latest results available',
        };
        foreach ($this->map($data['links'] ?? null) as $key => $path) {
            $view['links'][$key] = $this->workspaceUrl($this->text($path));
        }
        $attention = $this->map($data['needs_attention'] ?? null);
        $site = $this->map($data['site'] ?? null);
        $resourceChanges = $this->map($data['resource_changes'] ?? null);
        $issueGroups = [];
        foreach ($this->rows($attention['items'] ?? null) as $item) {
            $severity = in_array($item['severity'] ?? '', ['critical', 'error'], true) ? 'critical' : 'warning';
            $view[$severity]++;
            $groupId = $this->text($site['group_id'] ?? null);
            $pageId = $this->text($item['page_id'] ?? null);
            $identity = array_map(fn (string $field): string => $this->text($item[$field] ?? null), [
                    'page_id', 'page_url', 'source', 'severity', 'title', 'message', 'occurred_at',
            ]);
            $key = hash('sha256', serialize($identity));
            if (isset($issueGroups[$key])) {
                $index = $issueGroups[$key];
                $view['issues'][$index]['count'] = ($this->number($view['issues'][$index]['count']) ?? 0) + 1;
                continue;
            }
            $issueGroups[$key] = count($view['issues']);
            $view['issues'][] = [
                'count' => 1,
                'page_url' => $this->text($item['page_url'] ?? null),
                'severity' => $severity,
                'title' => $this->text($item['title'] ?? null),
                'message' => $this->text($item['message'] ?? null),
                'page' => $this->pageLabel($this->text($item['page_url'] ?? null)),
                'date' => $this->date($item['occurred_at'] ?? null),
                'url' => DashboardClient::validId($groupId) && DashboardClient::validId($pageId)
                ? $this->workspaceUrl(
                    '/app/tracker/groups/' . $groupId . '/pages/' . $pageId . '/issues?device=' . $device,
                )
                : '',
            ];
        }
        $view['issue_loaded'] = $view['critical'] + $view['warning'];
        $view['issue_total'] = $this->value($attention['total'] ?? $view['issue_loaded']);
        $view['issue_trends'] = [];
        $trend = $this->rows($data['issue_trend'] ?? null);
        $end = $trend !== [] ? $this->timestamp($trend[count($trend) - 1]['finished_at'] ?? null) : null;
        $dayStart = $end !== null ? strtotime(gmdate('Y-m-d', $end) . ' 00:00:00 UTC') : null;
        foreach ([7, 30, 90] as $days) {
            $rows = array_values(array_filter(
                $trend,
                fn (array $row): bool =>
                $dayStart !== null &&
                ($this->timestamp($row['finished_at'] ?? null) ?? 0) >= $dayStart - ($days - 1) * 86400
            ));
            $view['issue_trends'][$days] = $this->chart($rows, [
                    ['key' => 'critical', 'label' => 'Critical', 'unit' => 'count', 'color' => 'critical'],
                    ['key' => 'warning', 'label' => 'Warning', 'unit' => 'count', 'color' => 'warning'],
            ]);
        }
        $view['metrics'] = $this->metrics($this->rows($data['performance_history'] ?? null));
        $view['changes_available'] = !empty($resourceChanges['available']);
        $view['changes'] = [];
        $view['change_counts'] = ['all' => 0, 'images' => 0, 'code' => 0, 'other' => 0];
        $labels = ['added' =>
            'Added',
            'modified' =>
            'Modified',
            'removed' =>
            'Removed'];
        foreach ($this->rows($resourceChanges['items'] ?? null) as $item) {
            $url = $this->text($item['url'] ?? null);
            $path = parse_url($url, PHP_URL_PATH) ?: $url;
            $type = in_array(
                $item['resource_type'] ?? '',
                DashboardClient::RESOURCE_TYPES,
                true
            ) ? $item['resource_type'] : 'other';
            $filter = in_array($type, ['javascript', 'css'], true) ? 'code' : $type;
            $view['changes'][] = [
                'name' => basename($path),
                'path' => $path,
                'url' => $url,
                'type' => $type,
                'filter' => $filter,
                'icon' => match ($type) {
                'images' => 'IMG', 'javascript' => 'JS', 'css' => 'CSS', default => 'FILE'
                },
                'change' => $labels[$this->text($item['change_type'] ?? null)] ?? 'Changed',
                'delta' => $this->delta($item['delta_bytes'] ?? null, 'bytes'),
                'before' => $this->value($item['before_bytes'] ?? null, 'bytes'),
                'after' => $this->value($item['after_bytes'] ?? null, 'bytes'),
            ];
            $view['change_counts']['all']++;
            $view['change_counts'][$filter]++;
        }
        $view['transfer'] = $this->transfer($this->map($data['transfer'] ?? null), $device);
        return $view;
    }

    /**
     * Resolve only the browser routes exposed by the current ViewMend API.
     */
    public function workspaceUrl(string $path): string
    {
        if (strlen($path) > 2048 || preg_match('/[\\\\\x00-\x20\x7f]/', $path)) {
            return '';
        }
        $parts = parse_url($path);
        if (
            !is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])
            ||
            !preg_match(
                '#^/app/tracker/groups/[a-f0-9-]{36}/pages/[a-f0-9-]{36}/(?:issues|change-history)$#i',
                $parts['path'] ?? ''
            )
        ) {
            return '';
        }
        return 'https://viewmend.com' . $path;
    }

    public function value(mixed $value, string $unit = 'count'): string
    {
        $number = $this->number($value);
        if ($number === null) {
            return '—';
        }
        if ($unit === 'bytes') {
            $number = abs($number);
            return match (true) {
                $number >= 1048576 => number_format($number / 1048576, 1) . ' MB',
                $number >= 1024 => rtrim(rtrim(number_format($number / 1024, 1, '.', ''), '0'), '.') . ' KB',
                default => number_format($number, 0) . ' B',
            };
        }
        return match ($unit) {
            'seconds' => rtrim(rtrim(number_format($number / 1000, 2, '.', ''), '0'), '.') . ' s',
            'cls' => rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.'),
            'ms' => number_format($number) . ' ms',
            default => number_format($number),
        };
    }

    private function delta(mixed $value, string $unit = 'count'): string
    {
        $number = $this->number($value);
        return $number === null ? '—' : ($number > 0 ? '+' : ($number < 0 ? '−' : '')) . $this->value(
            abs($number),
            $unit
        );
    }

    /**
     * @param array<array<string, mixed>> $history
     *   Chronological performance measurements.
     *
     * @return array<array<string, mixed>>
     *   Four metrics, preserving missing measurements and comparison gaps.
     */
    private function metrics(array $history): array
    {
        $definitions = [
            [
                'key' => 'performance_score',
                'label' => 'Performance score',
                'unit' => 'count',
                'threshold' => 90,
                'lower' => false,
                'color' => 'performance',
            ],
            [
                'key' => 'lcp_ms',
                'label' => 'Largest Contentful Paint',
                'unit' => 'seconds',
                'threshold' => 2500,
                'lower' => true,
                'color' => 'lcp',
            ],
            [
                'key' => 'cls',
                'label' => 'Cumulative Layout Shift',
                'unit' => 'cls',
                'threshold' => 0.1,
                'lower' => true,
                'color' => 'cls',
            ],
            [
                'key' => 'total_blocking_time_ms',
                'label' => 'Total Blocking Time',
                'unit' => 'ms',
                'threshold' => 200,
                'lower' => true,
                'color' => 'tbt',
            ],
        ];
        $history = array_slice($history, -30);
        $last = count($history) - 1;
        foreach ($definitions as &$metric) {
            $value = $this->number($history[$last][$metric['key']] ?? null);
            $previous = $this->number($history[$last - 1][$metric['key']] ?? null);
            $good = $value !== null &&
            ($metric['lower'] ? $value <= $metric['threshold'] : $value >= $metric['threshold']);
            $metric['value'] = $this->value($value, $metric['unit']);
            $metric['delta'] = $value !== null &&
            $previous !== null
            ? $this->delta(
                $value - $previous,
                $metric['unit']
            ) : '—';
            $metric['worse'] = $value !== null &&
            $previous !== null &&
            ($metric['lower'] ? $value > $previous : $value < $previous);
            $metric['status'] = $value === null ? 'No data' : ($good ? 'Good' : 'Needs review');
            $metric['good'] = $good;
            $metric['threshold_label'] = ($metric['lower'] ? '≤ ' : '≥ ') . $this->value(
                $metric['threshold'],
                $metric['unit']
            );
            $metric['chart'] = $this->chart($history, [$metric], (float) $metric['threshold']);
        }
        return $definitions;
    }

    /**
     * @param array<array<string, mixed>> $rows
     *   Chronological source rows.
     * @param array<array<string, mixed>> $series
     *   Metric definitions.
     *
     * @return array<string, mixed>
     *   Numeric SVG geometry and the equivalent data table.
     */
    public function chart(array $rows, array $series, ?float $threshold = null): array
    {
        $maximum = max(1, ($threshold ?? 0) * 1.15);
        foreach ($rows as $row) {
            foreach ($series as $definition) {
                $maximum = max($maximum, ($this->number($row[$this->text($definition['key'])] ?? null) ?? 0) * 1.15);
            }
        }
        if (($series[0]['key'] ?? '') === 'performance_score') {
            $maximum = 100;
        }
        if (($series[0]['key'] ?? '') === 'critical') {
            $maximum = max(2, ceil($maximum / 2) * 2);
        }
        // CLS needs a fractional scale; the count chart's floor would flatten it.
        if (($series[0]['key'] ?? '') === 'cls') {
            $values = array_map(fn (array $row): float => ($this->number($row['cls'] ?? null) ?? 0) * 1.15, $rows);
            $maximum = max([0.12, ...$values]);
        }
        $chart = [
            'available' => count($rows) >= 2,
            'last_index' => max(0, count($rows) - 1),
            'lines' => [],
            'rows' => [],
            'series' => $series,
            'ticks' => [],
            'threshold_y' => $threshold !== null ? round(150 - $threshold / $maximum * 130, 2) : null,
        ];
        $last = max(1, count($rows) - 1);
        foreach ($series as $definition) {
            $path = '';
            $pen = false;
            foreach ($rows as $index => $row) {
                $value = $this->number($row[$this->text($definition['key'])] ?? null);
                if ($value === null) {
                    $pen = false;
                    continue;
                }
                $x = round(55 + $index / $last * 275, 2);
                $y = round(150 - max(0, $value) / $maximum * 130, 2);
                $path .= ($pen ? ' L ' : ' M ') . $x . ' ' . $y;
                $pen = true;
            }
            $chart['lines'][] = ['path' => $path, 'color' => $definition['color']];
        }
        foreach ($rows as $index => $row) {
            $values = array_map(
                fn (array $definition): string =>
                $this->value(
                    $row[$this->text($definition['key'])] ?? null,
                    $this->text($definition['unit'])
                ),
                $series
            );
            $chart['rows'][] = [
                'date' => $this->date($row['finished_at'] ?? null),
                'short_date' => $this->date($row['finished_at'] ?? null, true),
                'values' => $values,
                'reading' =>
                implode(
                    ' · ',
                    array_map(
                        fn (array $definition,
                            string $value): string =>
                        $this->text($definition['label']) . ': ' . $value,
                        $series,
                        $values
                    )
                ),
                'x' => round(55 + $index / $last * 275, 2),
            ];
        }
        for ($index = 0; $index <= 4; $index++) {
            $chart['ticks'][] = [
                'y' => 150 - $index * 32.5,
                'label' => $this->value($maximum * $index / 4, $this->text($series[0]['unit'])),
            ];
        }
        $chart['points'] = json_encode($chart['rows'], JSON_THROW_ON_ERROR);
        return $chart;
    }

    /**
     * @param array<string, mixed> $data
     *   Transfer summary.
     *
     * @return array<string, mixed>
     *   Donut and category controls.
     */
    private function transfer(array $data, string $device): array
    {
        $transfer = [
            'available' => !empty($data['available']),
            'total' => $this->value($data['total_bytes'] ?? null, 'bytes'),
            'categories' => [],
            'truncated' => !empty($data['truncated']),
            'stored' => $this->value($data['stored_requests'] ?? null),
            'reported' => $this->value($data['reported_requests'] ?? null),
            'unattributed' => $this->value($data['unattributed_bytes'] ?? null, 'bytes'),
            'has_unattributed' => ($this->number($data['unattributed_bytes'] ?? null) ?? 0) > 0,
        ];
        $total = max(1, $this->number($data['total_bytes'] ?? null) ?? 1);
        $offset = 0;
        $labels = [
            'images' => 'Images',
            'javascript' => 'JavaScript',
            'css' => 'CSS',
            'other' => 'Other',
        ];
        foreach ($this->rows($data['categories'] ?? null) as $row) {
            $key = $this->text($row['key'] ?? null);
            if (!isset($labels[$key])) {
                continue;
            }
            $length = min(100 - $offset, max(0, $this->number($row['bytes'] ?? null) ?? 0) / $total * 100);
            $url = DashboardClient::validId($this->text($data['run_id'] ?? null))
            ? (string) $this->uris->buildUriFromRoute(
                'tools_viewmendtracker.resources',
                [
                    'run_id' => $data['run_id'], 'type' => $key, 'device' => $device,
                ]
            ) : '';
            $transfer['categories'][] = [
                'key' => $key,
                'label' => $labels[$key],
                'bytes' => $this->value($row['bytes'] ?? null, 'bytes'),
                'length' => round($length, 3),
                'offset' => round(-$offset, 3),
                'url' => $url,
            ];
            $offset += $length;
        }
        return $transfer;
    }

    private function date(mixed $value, bool $short = false): string
    {
        $timestamp = $this->timestamp($value);
        return $timestamp === null ? (string) 'Not checked yet' : date(
            $short ? 'M j' : 'M j, Y · H:i',
            $timestamp
        );
    }

    private function timestamp(mixed $value): ?int
    {
        $timestamp = is_string($value) && $value !== '' ? strtotime($value) : false;
        return $timestamp === false ? null : $timestamp;
    }

    private function pageLabel(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: parse_url($url, PHP_URL_HOST) ?: $url);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     *   Safe associative data.
     */
    private function map(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @return array<array<string, mixed>>
     *   Only object-shaped rows.
     */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_map($this->map(...), array_values(array_filter($value, 'is_array'))) : [];
    }
}
