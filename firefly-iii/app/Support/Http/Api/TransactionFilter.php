<?php

/**
 * TransactionFilter.php
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

namespace FireflyIII\Support\Http\Api;

use FireflyIII\Enums\TransactionTypeEnum;

/**
 * Trait TransactionFilter
 */
trait TransactionFilter
{
    /**
     * All the types you can request.
     */
    protected function mapTransactionTypes(string $type): array
    {
        $types  = [
            'all'             => [
                TransactionTypeEnum::WITHDRAWAL->value,
                TransactionTypeEnum::DEPOSIT->value,
                TransactionTypeEnum::TRANSFER->value,
                TransactionTypeEnum::OPENING_BALANCE->value,
                TransactionTypeEnum::RECONCILIATION->value,
            ],
            'withdrawal'      => [TransactionTypeEnum::WITHDRAWAL->value],
            'withdrawals'     => [TransactionTypeEnum::WITHDRAWAL->value],
            'expense'         => [TransactionTypeEnum::WITHDRAWAL->value],
            'expenses'        => [TransactionTypeEnum::WITHDRAWAL->value],
            'income'          => [TransactionTypeEnum::DEPOSIT->value],
            'deposit'         => [TransactionTypeEnum::DEPOSIT->value],
            'deposits'        => [TransactionTypeEnum::DEPOSIT->value],
            'transfer'        => [TransactionTypeEnum::TRANSFER->value],
            'transfers'       => [TransactionTypeEnum::TRANSFER->value],
            'opening_balance' => [TransactionTypeEnum::OPENING_BALANCE->value],
            'reconciliation'  => [TransactionTypeEnum::RECONCILIATION->value],
            'reconciliations' => [TransactionTypeEnum::RECONCILIATION->value],
            'special'         => [TransactionTypeEnum::OPENING_BALANCE->value, TransactionTypeEnum::RECONCILIATION->value],
            'specials'        => [TransactionTypeEnum::OPENING_BALANCE->value, TransactionTypeEnum::RECONCILIATION->value],
            'default'         => [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value],
        ];
        $return = [];
        $parts  = explode(',', $type);
        foreach ($parts as $part) {
            $return = array_merge($return, $types[$part] ?? $types['default']);
        }

        return array_unique($return);
    }
}
