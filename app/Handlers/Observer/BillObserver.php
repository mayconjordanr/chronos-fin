<?php

/*
 * BillObserver.php
 * Copyright (c) 2023 james@firefly-iii.org
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

namespace FireflyIII\Handlers\Observer;

use FireflyIII\Models\Attachment;
use FireflyIII\Models\Bill;
use FireflyIII\Repositories\Attachment\AttachmentRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Http\Api\ExchangeRateConverter;
use Illuminate\Support\Facades\Log;

/**
 * Class BillObserver
 */
class BillObserver
{
    public function created(Bill $bill): void
    {
        //        Log::debug('Observe "created" of a bill.');
        $this->updatePrimaryCurrencyAmount($bill);
    }

    private function updatePrimaryCurrencyAmount(Bill $bill): void
    {
        if (!Amount::convertToPrimary($bill->user)) {
            return;
        }
        $userCurrency            = app('amount')->getPrimaryCurrencyByUserGroup($bill->user->userGroup);
        $bill->native_amount_min = null;
        $bill->native_amount_max = null;
        if ($bill->transactionCurrency->id !== $userCurrency->id) {
            $converter               = new ExchangeRateConverter();
            $converter->setUserGroup($bill->user->userGroup);
            $converter->setIgnoreSettings(true);
            $bill->native_amount_min = $converter->convert($bill->transactionCurrency, $userCurrency, today(), $bill->amount_min);
            $bill->native_amount_max = $converter->convert($bill->transactionCurrency, $userCurrency, today(), $bill->amount_max);
        }
        $bill->saveQuietly();
        Log::debug('Bill primary currency amounts are updated.');
    }

    public function deleting(Bill $bill): void
    {
        $repository = app(AttachmentRepositoryInterface::class);
        $repository->setUser($bill->user);

        //        app('log')->debug('Observe "deleting" of a bill.');
        /** @var Attachment $attachment */
        foreach ($bill->attachments()->get() as $attachment) {
            $repository->destroy($attachment);
        }
        $bill->notes()->delete();
    }

    public function updated(Bill $bill): void
    {
        //        Log::debug('Observe "updated" of a bill.');
        $this->updatePrimaryCurrencyAmount($bill);
    }
}
