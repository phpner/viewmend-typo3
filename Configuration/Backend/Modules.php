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

use ViewMend\Typo3SiteTracker\Controller\ModuleController;

return [
    'tools_viewmendtracker' => [
        'parent' => 'viewmend',
        'position' => ['after' => 'viewmend_dashboard'],
        'access' => 'systemMaintainer',
        'path' => '/module/viewmend/site-tracker',
        'iconIdentifier' => 'viewmend-product-site-tracker',
        'labels' => 'LLL:EXT:viewmend_site_tracker/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ModuleController::class . '::overview',
                'methods' => ['GET'],
            ],
            'connection' => [
                'target' => ModuleController::class . '::connection',
                'methods' => ['GET'],
            ],
            'connection-save' => [
                'target' => ModuleController::class . '::saveConnection',
                'methods' => ['POST'],
            ],
            'rules' => [
                'target' => ModuleController::class . '::rules',
                'methods' => ['GET'],
            ],
            'rules-save' => [
                'target' => ModuleController::class . '::saveRules',
                'methods' => ['POST'],
            ],
            'activity' => [
                'target' => ModuleController::class . '::activity',
                'methods' => ['GET'],
            ],
            'activity-filter' => [
                'target' => ModuleController::class . '::filterActivity',
                'methods' => ['POST'],
            ],
            'activity-action' => [
                'target' => ModuleController::class . '::activityAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
