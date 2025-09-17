<?php

/**
 * HomeController.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Admin;

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Http\Middleware\IsDemoUser;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Class HomeController.
 */
class HomeController extends Controller
{
    /**
     * ConfigurationController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->middleware(IsDemoUser::class)->except(['index']);
    }

    /**
     * Index of the admin.
     *
     * @return Factory|View
     */
    public function index()
    {
        Log::channel('audit')->info('User visits admin index.');
        $title         = (string) trans('firefly.system_settings');
        $mainTitleIcon = 'fa-hand-spock-o';
        $email         = auth()->user()->email;
        $pref          = app('preferences')->get('remote_guard_alt_email');
        if (null !== $pref && is_string($pref->data)) {
            $email = $pref->data;
        }

        return view('settings.index', compact('title', 'mainTitleIcon', 'email'));
    }
}
