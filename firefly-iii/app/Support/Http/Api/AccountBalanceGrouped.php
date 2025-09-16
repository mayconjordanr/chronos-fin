<?php

/*
 * AccountBalanceGrouped.php
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

namespace FireflyIII\Support\Http\Api;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class AccountBalanceGrouped
 */
class AccountBalanceGrouped
{
    private array                          $accountIds;
    private string                         $carbonFormat;
    private readonly ExchangeRateConverter $converter;
    private array                          $currencies = [];
    private array                          $data       = [];
    private TransactionCurrency            $primary;
    private Carbon                         $end;
    private array                          $journals   = [];
    private string                         $preferredRange;
    private Carbon                         $start;

    public function __construct()
    {
        $this->accountIds = [];
        $this->converter  = app(ExchangeRateConverter::class);
    }

    /**
     * Convert the given input to a chart compatible array.
     */
    public function convertToChartData(): array
    {
        $chartData = [];

        // loop2: loop this data, make chart bars for each currency:
        /** @var array $currency */
        foreach ($this->data as $currency) {
            // income and expense array prepped:
            $income       = [
                'label'                           => 'earned',
                'currency_id'                     => (string)$currency['currency_id'],
                'currency_symbol'                 => $currency['currency_symbol'],
                'currency_code'                   => $currency['currency_code'],
                'currency_decimal_places'         => $currency['currency_decimal_places'],
                'primary_currency_id'             => (string)$currency['primary_currency_id'],
                'primary_currency_symbol'         => $currency['primary_currency_symbol'],
                'primary_currency_code'           => $currency['primary_currency_code'],
                'primary_currency_decimal_places' => $currency['primary_currency_decimal_places'],
                'date'                            => $this->start->toAtomString(),
                'start_date'                      => $this->start->toAtomString(),
                'end_date'                        => $this->end->toAtomString(),
                'yAxisID'                         => 0,
                'type'                            => 'line',
                'period'                          => $this->preferredRange,
                'entries'                         => [],
                'pc_entries'                      => [],
            ];
            $expense      = [
                'label'                           => 'spent',
                'currency_id'                     => (string)$currency['currency_id'],
                'currency_symbol'                 => $currency['currency_symbol'],
                'currency_code'                   => $currency['currency_code'],
                'currency_decimal_places'         => $currency['currency_decimal_places'],
                'primary_currency_id'             => (string)$currency['primary_currency_id'],
                'primary_currency_symbol'         => $currency['primary_currency_symbol'],
                'primary_currency_code'           => $currency['primary_currency_code'],
                'primary_currency_decimal_places' => $currency['primary_currency_decimal_places'],
                'date'                            => $this->start->toAtomString(),
                'start_date'                      => $this->start->toAtomString(),
                'end_date'                        => $this->end->toAtomString(),
                'type'                            => 'line',
                'yAxisID'                         => 0,
                'period'                          => $this->preferredRange,
                'entries'                         => [],
                'pc_entries'                      => [],
            ];
            // loop all possible periods between $start and $end, and add them to the correct dataset.
            $currentStart = clone $this->start;
            while ($currentStart <= $this->end) {
                $key                           = $currentStart->format($this->carbonFormat);
                $label                         = $currentStart->toAtomString();
                // normal entries
                $income['entries'][$label]     = Steam::bcround($currency[$key]['earned'] ?? '0', $currency['currency_decimal_places']);
                $expense['entries'][$label]    = Steam::bcround($currency[$key]['spent'] ?? '0', $currency['currency_decimal_places']);

                // converted entries
                $income['pc_entries'][$label]  = Steam::bcround($currency[$key]['pc_earned'] ?? '0', $currency['primary_currency_decimal_places']);
                $expense['pc_entries'][$label] = Steam::bcround($currency[$key]['pc_spent'] ?? '0', $currency['primary_currency_decimal_places']);

                // next loop
                $currentStart                  = Navigation::addPeriod($currentStart, $this->preferredRange, 0);
            }

            $chartData[]  = $income;
            $chartData[]  = $expense;
        }

        return $chartData;
    }

    /**
     * Group the given journals by currency and then by period.
     * If they are part of a set of accounts this basically means it's balance chart.
     */
    public function groupByCurrencyAndPeriod(): void
    {
        Log::debug(sprintf('Created new ExchangeRateConverter in %s', __METHOD__));
        $converter = new ExchangeRateConverter();

        // loop. group by currency and by period.
        /** @var array $journal */
        foreach ($this->journals as $journal) {
            $this->processJournal($journal);
        }
        $converter->summarize();
    }

    private function processJournal(array $journal): void
    {
        // format the date according to the period
        $period                                          = $journal['date']->format($this->carbonFormat);
        $currencyId                                      = (int)$journal['currency_id'];
        $currency                                        = $this->findCurrency($currencyId);

        // set the array with monetary info, if it does not exist.
        $this->createDefaultDataEntry($journal);
        // set the array (in monetary info) with spent/earned in this $period, if it does not exist.
        $this->createDefaultPeriodEntry($journal);

        // is this journal's amount in- our outgoing?
        $key                                             = $this->getDataKey($journal);
        $amount                                          = 'spent' === $key ? Steam::negative($journal['amount']) : Steam::positive($journal['amount']);

        // get conversion rate
        $rate                                            = $this->getRate($currency, $journal['date']);
        $amountConverted                                 = bcmul($amount, $rate);

        // perhaps transaction already has the foreign amount in the primary currency.
        if ((int)$journal['foreign_currency_id'] === $this->primary->id) {
            $amountConverted = $journal['foreign_amount'] ?? '0';
            $amountConverted = 'earned' === $key ? Steam::positive($amountConverted) : Steam::negative($amountConverted);
        }

        // add normal entry
        $this->data[$currencyId][$period][$key]          = bcadd((string)$this->data[$currencyId][$period][$key], $amount);

        // add converted entry
        $convertedKey                                    = sprintf('pc_%s', $key);
        $this->data[$currencyId][$period][$convertedKey] = bcadd((string)$this->data[$currencyId][$period][$convertedKey], $amountConverted);
    }

    private function findCurrency(int $currencyId): TransactionCurrency
    {
        if (array_key_exists($currencyId, $this->currencies)) {
            return $this->currencies[$currencyId];
        }
        $this->currencies[$currencyId] = Amount::getTransactionCurrencyById($currencyId);

        return $this->currencies[$currencyId];
    }

    private function createDefaultDataEntry(array $journal): void
    {
        $currencyId = (int)$journal['currency_id'];
        $this->data[$currencyId] ??= [
            'currency_id'                     => (string)$currencyId,
            'currency_symbol'                 => $journal['currency_symbol'],
            'currency_code'                   => $journal['currency_code'],
            'currency_name'                   => $journal['currency_name'],
            'currency_decimal_places'         => $journal['currency_decimal_places'],
            // primary currency info (could be the same)
            'primary_currency_id'             => (string)$this->primary->id,
            'primary_currency_code'           => $this->primary->code,
            'primary_currency_symbol'         => $this->primary->symbol,
            'primary_currency_decimal_places' => $this->primary->decimal_places,
        ];
    }

    private function createDefaultPeriodEntry(array $journal): void
    {
        $currencyId = (int)$journal['currency_id'];
        $period     = $journal['date']->format($this->carbonFormat);
        $this->data[$currencyId][$period] ??= [
            'period'    => $period,
            'spent'     => '0',
            'earned'    => '0',
            'pc_spent'  => '0',
            'pc_earned' => '0',
        ];
    }

    private function getDataKey(array $journal): string
    {
        // deposit = incoming
        // transfer or reconcile or opening balance, and these accounts are the destination.
        if (
            TransactionTypeEnum::DEPOSIT->value === $journal['transaction_type_type']

            || (
                (
                    TransactionTypeEnum::TRANSFER->value === $journal['transaction_type_type']
                    || TransactionTypeEnum::RECONCILIATION->value === $journal['transaction_type_type']
                    || TransactionTypeEnum::OPENING_BALANCE->value === $journal['transaction_type_type']
                )
                && in_array($journal['destination_account_id'], $this->accountIds, true)
            )
        ) {
            return 'earned';
        }

        return 'spent';
    }

    private function getRate(TransactionCurrency $currency, Carbon $date): string
    {
        try {
            $rate = $this->converter->getCurrencyRate($currency, $this->primary, $date);
        } catch (FireflyException $e) {
            app('log')->error($e->getMessage());
            $rate = '1';
        }

        return $rate;
    }

    public function setAccounts(Collection $accounts): void
    {
        $this->accountIds = $accounts->pluck('id')->toArray();
    }

    public function setPrimary(TransactionCurrency $primary): void
    {
        $this->primary                  = $primary;
        $primaryCurrencyId              = $primary->id;
        $this->currencies               = [$primary->id => $primary]; // currency cache
        $this->data[$primaryCurrencyId] = [
            'currency_id'                     => (string)$primaryCurrencyId,
            'currency_symbol'                 => $primary->symbol,
            'currency_code'                   => $primary->code,
            'currency_name'                   => $primary->name,
            'currency_decimal_places'         => $primary->decimal_places,
            'primary_currency_id'             => (string)$primaryCurrencyId,
            'primary_currency_symbol'         => $primary->symbol,
            'primary_currency_code'           => $primary->code,
            'primary_currency_name'           => $primary->name,
            'primary_currency_decimal_places' => $primary->decimal_places,
        ];
    }

    public function setEnd(Carbon $end): void
    {
        $this->end = $end;
    }

    public function setJournals(array $journals): void
    {
        $this->journals = $journals;
    }

    public function setPreferredRange(string $preferredRange): void
    {
        $this->preferredRange = $preferredRange;
        $this->carbonFormat   = Navigation::preferredCarbonFormatByPeriod($preferredRange);
    }

    public function setStart(Carbon $start): void
    {
        $this->start = $start;
    }
}
