<?php

/**
 * OperationsRepositoryInterface.php
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

namespace FireflyIII\Repositories\Budget;

use Carbon\Carbon;
use Deprecated;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\Budget;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Interface OperationsRepositoryInterface
 *
 * @method setUserGroup(UserGroup $group)
 * @method getUserGroup()
 * @method getUser()
 * @method checkUserGroupAccess(UserRoleEnum $role)
 * @method setUser(null|Authenticatable|User $user)
 * @method setUserGroupById(int $userGroupId)
 */
interface OperationsRepositoryInterface
{
    /**
     * A method that returns the amount of money budgeted per day for this budget,
     * on average.
     */
    public function budgetedPerDay(Budget $budget): string;

    #[Deprecated]
    public function getBudgetPeriodReport(Collection $budgets, Collection $accounts, Carbon $start, Carbon $end): array;

    /**
     * This method returns a list of all the withdrawal transaction journals (as arrays) set in that period
     * which have the specified budget set to them. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always negative.
     */
    public function listExpenses(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $budgets = null): array;

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumExpenses(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $budgets = null,
        ?TransactionCurrency $currency = null,
        bool                 $convertToPrimary = false
    ): array;

    public function sumCollectedExpenses(array $expenses, Carbon $start, Carbon $end, TransactionCurrency $transactionCurrency, bool $convertToPrimary = false): array;

    public function sumCollectedExpensesByBudget(array $expenses, Budget $budget, bool $convertToPrimary = false): array;

    public function collectExpenses(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $budgets = null, ?TransactionCurrency $currency = null): array;
}
