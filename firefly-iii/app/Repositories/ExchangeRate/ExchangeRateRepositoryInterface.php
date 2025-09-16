<?php

/*
 * ExchangeRateRepositoryInterface.php
 * Copyright (c) 2025 james@firefly-iii.org.
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
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

declare(strict_types=1);

namespace FireflyIII\Repositories\ExchangeRate;

use Carbon\Carbon;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Interface ExchangeRateRepositoryInterface
 *
 * @method setUserGroup(UserGroup $group)
 * @method getUserGroup()
 * @method getUser()
 * @method checkUserGroupAccess(UserRoleEnum $role)
 * @method setUser(null|Authenticatable|User $user)
 * @method setUserGroupById(int $userGroupId)
 */
interface ExchangeRateRepositoryInterface
{
    public function deleteRate(CurrencyExchangeRate $rate): void;

    public function deleteRates(TransactionCurrency $from, TransactionCurrency $to): void;

    public function getAll(): Collection;

    public function getRates(TransactionCurrency $from, TransactionCurrency $to): Collection;

    public function getSpecificRateOnDate(TransactionCurrency $from, TransactionCurrency $to, Carbon $date): ?CurrencyExchangeRate;

    public function storeExchangeRate(TransactionCurrency $from, TransactionCurrency $to, string $rate, Carbon $date): CurrencyExchangeRate;

    public function updateExchangeRate(CurrencyExchangeRate $object, string $rate, ?Carbon $date = null): CurrencyExchangeRate;
}
