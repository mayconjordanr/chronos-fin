<?php

/*
 * PurgeController.php
 * Copyright (c) 2022 james@firefly-iii.org
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

namespace FireflyIII\Api\V1\Controllers\Data;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;

/**
 * Class PurgeController
 */
class PurgeController extends Controller
{
    protected array $acceptedRoles = [UserRoleEnum::FULL];

    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $this->validateUserGroup($request);

                return $next($request);
            }
        );
    }

    /**
     * TODO cleanup and use repositories.
     */
    public function purge(): JsonResponse
    {
        /** @var User $user */
        $user       = auth()->user();

        // some manual code, too lazy to call all repositories.

        // budgets:
        Budget::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // bills
        Bill::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // piggies
        $repository = app(PiggyBankRepositoryInterface::class);
        $repository->setUser($user);
        $repository->purgeAll();

        // rule group
        RuleGroup::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // rules
        Rule::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // recurring transactions
        Recurrence::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // categories
        Category::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // tags
        Tag::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // accounts
        Account::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // transaction groups
        TransactionGroup::whereUserId($user->id)->onlyTrashed()->forceDelete();

        // transaction journals
        TransactionJournal::whereUserId($user->id)->onlyTrashed()->forceDelete();

        return response()->json([], 204);
    }
}
