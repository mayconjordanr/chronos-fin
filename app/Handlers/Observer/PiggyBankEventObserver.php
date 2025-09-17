<?php

/*
 * AutoBudgetObserver.php
 * Copyright (c) 2024 james@firefly-iii.org.
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

namespace FireflyIII\Handlers\Observer;

use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Http\Api\ExchangeRateConverter;
use Illuminate\Support\Facades\Log;

class PiggyBankEventObserver
{
    public function created(PiggyBankEvent $event): void
    {
        Log::debug('Observe "created" of a piggy bank event.');
        $this->updatePrimaryCurrencyAmount($event);
    }

    private function updatePrimaryCurrencyAmount(PiggyBankEvent $event): void
    {
        $user                 = $event->piggyBank->accounts()->first()?->user;
        if (null === $user) {
            Log::warning('Piggy bank seems to have no accounts. Break.');

            return;
        }
        if (!Amount::convertToPrimary($user)) {
            return;
        }
        $userCurrency         = app('amount')->getPrimaryCurrencyByUserGroup($event->piggyBank->accounts()->first()->user->userGroup);
        $event->native_amount = null;
        if ($event->piggyBank->transactionCurrency->id !== $userCurrency->id) {
            $converter            = new ExchangeRateConverter();
            $converter->setUserGroup($event->piggyBank->accounts()->first()->user->userGroup);
            $converter->setIgnoreSettings(true);
            $event->native_amount = $converter->convert($event->piggyBank->transactionCurrency, $userCurrency, today(), $event->amount);
        }
        $event->saveQuietly();
        Log::debug('Piggy bank event primary currency amount is updated.');
    }

    public function updated(PiggyBankEvent $event): void
    {
        Log::debug('Observe "updated" of a piggy bank event.');
        $this->updatePrimaryCurrencyAmount($event);
    }
}
