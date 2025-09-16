<?php

/*
 * PeriodController.php
 * Copyright (c) 2021 james@firefly-iii.org
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

namespace FireflyIII\Api\V1\Controllers\Insight\Expense;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Insight\GenericRequest;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Support\Facades\Amount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Class PeriodController
 */
class PeriodController extends Controller
{
    public function total(GenericRequest $request): JsonResponse
    {
        $accounts         = $request->getAssetAccounts();
        $start            = $request->getStart();
        $end              = $request->getEnd();
        $response         = [];
        $convertToPrimary = Amount::convertToPrimary();
        $primary          = Amount::getPrimaryCurrency();

        // collect all expenses in this period (regardless of type)
        $collector        = app(GroupCollectorInterface::class);
        $collector->setTypes([TransactionTypeEnum::WITHDRAWAL->value])->setRange($start, $end)->setSourceAccounts($accounts);
        $genericSet       = $collector->getExtractedJournals();
        foreach ($genericSet as $journal) {
            // same code as many other sumExpense methods. I think this needs some kind of generic method.
            $amount                                    = '0';
            $currencyId                                = (int) $journal['currency_id'];
            $currencyCode                              = $journal['currency_code'];
            if ($convertToPrimary) {
                $amount = Amount::getAmountFromJournal($journal);
                if ($primary->id !== (int) $journal['currency_id'] && $primary->id !== (int) $journal['foreign_currency_id']) {
                    $currencyId   = $primary->id;
                    $currencyCode = $primary->code;
                }
                if ($primary->id !== (int) $journal['currency_id'] && $primary->id === (int) $journal['foreign_currency_id']) {
                    $currencyId   = $journal['foreign_currency_id'];
                    $currencyCode = $journal['foreign_currency_code'];
                }
                Log::debug(sprintf('[a] Add amount %s %s', $currencyCode, $amount));
            }
            if (!$convertToPrimary) {
                // ignore the amount in foreign currency.
                Log::debug(sprintf('[b] Add amount %s %s', $currencyCode, $journal['amount']));
                $amount = $journal['amount'];
            }


            $response[$currencyId] ??= [
                'difference'       => '0',
                'difference_float' => 0,
                'currency_id'      => (string) $currencyId,
                'currency_code'    => $currencyCode,
            ];
            $response[$currencyId]['difference']       = bcadd($response[$currencyId]['difference'], $amount);
            $response[$currencyId]['difference_float'] = (float) $response[$currencyId]['difference']; // intentional float
        }

        return response()->json(array_values($response));
    }
}
