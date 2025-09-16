<?php

/**
 * OperationsRepository.php
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

namespace FireflyIII\Repositories\Account;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Report\Summarizer\TransactionSummarizer;
use FireflyIII\Support\Repositories\UserGroup\UserGroupInterface;
use FireflyIII\Support\Repositories\UserGroup\UserGroupTrait;
use Illuminate\Support\Collection;

/**
 * Class OperationsRepository
 */
class OperationsRepository implements OperationsRepositoryInterface, UserGroupInterface
{
    use UserGroupTrait;

    /**
     * This method returns a list of all the withdrawal transaction journals (as arrays) set in that period
     * which have the specified accounts. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always negative.
     */
    public function listExpenses(Carbon $start, Carbon $end, Collection $accounts): array
    {
        $journals = $this->getTransactions($start, $end, $accounts, TransactionTypeEnum::WITHDRAWAL->value);

        return $this->sortByCurrency($journals, 'negative');
    }

    /**
     * Collect transactions with some parameters
     */
    private function getTransactions(Carbon $start, Carbon $end, Collection $accounts, string $type): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([$type]);
        $collector->setBothAccounts($accounts);
        $collector->withCategoryInformation()->withAccountInformation()->withBudgetInformation()->withTagInformation();

        return $collector->getExtractedJournals();
    }

    private function sortByCurrency(array $journals, string $direction): array
    {
        $array = [];
        foreach ($journals as $journal) {
            $currencyId                                             = (int) $journal['currency_id'];
            $journalId                                              = (int) $journal['transaction_journal_id'];
            $array[$currencyId] ??= [
                'currency_id'             => $journal['currency_id'],
                'currency_name'           => $journal['currency_name'],
                'currency_symbol'         => $journal['currency_symbol'],
                'currency_code'           => $journal['currency_code'],
                'currency_decimal_places' => $journal['currency_decimal_places'],
                'transaction_journals'    => [],
            ];

            $array[$currencyId]['transaction_journals'][$journalId] = [
                'amount'                   => Steam::{$direction}((string) $journal['amount']), // @phpstan-ignore-line
                'date'                     => $journal['date'],
                'transaction_journal_id'   => $journalId,
                'budget_name'              => $journal['budget_name'],
                'category_name'            => $journal['category_name'],
                'source_account_id'        => $journal['source_account_id'],
                'source_account_name'      => $journal['source_account_name'],
                'source_account_iban'      => $journal['source_account_iban'],
                'destination_account_id'   => $journal['destination_account_id'],
                'destination_account_name' => $journal['destination_account_name'],
                'destination_account_iban' => $journal['destination_account_iban'],
                'tags'                     => $journal['tags'],
                'description'              => $journal['description'],
                'transaction_group_id'     => $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    /**
     * This method returns a list of all the deposit transaction journals (as arrays) set in that period
     * which have the specified accounts. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always positive.
     */
    public function listIncome(Carbon $start, Carbon $end, ?Collection $accounts = null): array
    {
        $journals = $this->getTransactions($start, $end, $accounts, TransactionTypeEnum::DEPOSIT->value);

        return $this->sortByCurrency($journals, 'positive');
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumExpenses(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $expense = null,
        ?TransactionCurrency $currency = null
    ): array {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::WITHDRAWAL->value, $start, $end, $accounts, $expense, $currency);

        return $this->groupByCurrency($journals, 'negative');
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    private function getTransactionsForSum(
        string               $type,
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $opposing = null,
        ?TransactionCurrency $currency = null
    ): array {
        $start->startOfDay();
        $end->endOfDay();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([$type])->withAccountInformation();

        // depends on transaction type:
        if (TransactionTypeEnum::WITHDRAWAL->value === $type) {
            if ($accounts instanceof Collection) {
                $collector->setSourceAccounts($accounts);
            }
            if ($opposing instanceof Collection) {
                $collector->setDestinationAccounts($opposing);
            }
        }
        if (TransactionTypeEnum::DEPOSIT->value === $type) {
            if ($accounts instanceof Collection) {
                $collector->setDestinationAccounts($accounts);
            }
            if ($opposing instanceof Collection) {
                $collector->setSourceAccounts($opposing);
            }
        }
        // supports only accounts, not opposing.
        if (TransactionTypeEnum::TRANSFER->value === $type && $accounts instanceof Collection) {
            $collector->setAccounts($accounts);
        }

        if ($currency instanceof TransactionCurrency) {
            $collector->setCurrency($currency);
        }
        $journals  = $collector->getExtractedJournals();

        // same but for foreign currencies:
        if ($currency instanceof TransactionCurrency) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector->setUser($this->user)->setRange($start, $end)->setTypes([$type])->withAccountInformation()
                ->setForeignCurrency($currency)
            ;
            if (TransactionTypeEnum::WITHDRAWAL->value === $type) {
                if ($accounts instanceof Collection) {
                    $collector->setSourceAccounts($accounts);
                }
                if ($opposing instanceof Collection) {
                    $collector->setDestinationAccounts($opposing);
                }
            }
            if (TransactionTypeEnum::DEPOSIT->value === $type) {
                if ($accounts instanceof Collection) {
                    $collector->setDestinationAccounts($accounts);
                }
                if ($opposing instanceof Collection) {
                    $collector->setSourceAccounts($opposing);
                }
            }

            $result    = $collector->getExtractedJournals();

            // do not use array_merge because you want keys to overwrite (otherwise you get double results):
            $journals  = $result + $journals;
        }

        return $journals;
    }

    private function groupByCurrency(array $journals, string $direction): array
    {
        $summarizer = new TransactionSummarizer($this->user);

        return $summarizer->groupByCurrencyId($journals, $direction);
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumExpensesByDestination(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $expense = null,
        ?TransactionCurrency $currency = null
    ): array {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::WITHDRAWAL->value, $start, $end, $accounts, $expense, $currency);

        return $this->groupByDirection($journals, 'destination', 'negative');
    }

    private function groupByDirection(array $journals, string $direction, string $method): array
    {
        $summarizer = new TransactionSummarizer($this->user);

        return $summarizer->groupByDirection($journals, $method, $direction);
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumExpensesBySource(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $expense = null,
        ?TransactionCurrency $currency = null
    ): array {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::WITHDRAWAL->value, $start, $end, $accounts, $expense, $currency);

        return $this->groupByDirection($journals, 'source', 'negative');
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumIncome(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $revenue = null,
        ?TransactionCurrency $currency = null
    ): array {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::DEPOSIT->value, $start, $end, $accounts, $revenue, $currency);

        return $this->groupByCurrency($journals, 'positive');
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumIncomeByDestination(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $revenue = null,
        ?TransactionCurrency $currency = null
    ): array {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::DEPOSIT->value, $start, $end, $accounts, $revenue, $currency);

        return $this->groupByDirection($journals, 'destination', 'positive');
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function sumIncomeBySource(
        Carbon               $start,
        Carbon               $end,
        ?Collection          $accounts = null,
        ?Collection          $revenue = null,
        ?TransactionCurrency $currency = null
    ): array {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::DEPOSIT->value, $start, $end, $accounts, $revenue, $currency);

        return $this->groupByDirection($journals, 'source', 'positive');
    }

    public function sumTransfers(Carbon $start, Carbon $end, ?Collection $accounts = null, ?TransactionCurrency $currency = null): array
    {
        $journals = $this->getTransactionsForSum(TransactionTypeEnum::TRANSFER->value, $start, $end, $accounts, null, $currency);

        return $this->groupByEither($journals);
    }

    private function groupByEither(array $journals): array
    {
        $return = [];

        /** @var array $journal */
        foreach ($journals as $journal) {
            $return = $this->groupByEitherJournal($return, $journal);
        }
        $final  = [];
        foreach ($return as $array) {
            $array['difference_float'] = (float) $array['difference'];
            $array['in_float']         = (float) $array['in'];
            $array['out_float']        = (float) $array['out'];
            $final[]                   = $array;
        }

        return $final;
    }

    private function groupByEitherJournal(array $return, array $journal): array
    {
        $sourceId                         = $journal['source_account_id'];
        $destinationId                    = $journal['destination_account_id'];
        $currencyId                       = $journal['currency_id'];
        $sourceKey                        = sprintf('%d-%d', $currencyId, $sourceId);
        $destKey                          = sprintf('%d-%d', $currencyId, $destinationId);
        $amount                           = Steam::positive($journal['amount']);

        // source first
        $return[$sourceKey] ??= [
            'id'               => (string) $sourceId,
            'name'             => $journal['source_account_name'],
            'difference'       => '0',
            'difference_float' => 0,
            'in'               => '0',
            'in_float'         => 0,
            'out'              => '0',
            'out_float'        => 0,
            'currency_id'      => (string) $currencyId,
            'currency_code'    => $journal['currency_code'],
        ];

        // dest next:
        $return[$destKey]   ??= [
            'id'               => (string) $destinationId,
            'name'             => $journal['destination_account_name'],
            'difference'       => '0',
            'difference_float' => 0,
            'in'               => '0',
            'in_float'         => 0,
            'out'              => '0',
            'out_float'        => 0,
            'currency_id'      => (string) $currencyId,
            'currency_code'    => $journal['currency_code'],
        ];

        // source account? money goes out!
        $return[$sourceKey]['out']        = bcadd((string) $return[$sourceKey]['out'], Steam::negative($amount));
        $return[$sourceKey]['difference'] = bcadd($return[$sourceKey]['out'], (string) $return[$sourceKey]['in']);

        // destination  account? money comes in:
        $return[$destKey]['in']           = bcadd((string) $return[$destKey]['in'], $amount);
        $return[$destKey]['difference']   = bcadd((string) $return[$destKey]['out'], $return[$destKey]['in']);

        // foreign currency
        if (null !== $journal['foreign_currency_id'] && null !== $journal['foreign_amount']) {
            $currencyId                       = $journal['foreign_currency_id'];
            $sourceKey                        = sprintf('%d-%d', $currencyId, $sourceId);
            $destKey                          = sprintf('%d-%d', $currencyId, $destinationId);
            $amount                           = Steam::positive($journal['foreign_amount']);

            // same as above:
            // source first
            $return[$sourceKey] ??= [
                'id'               => (string) $sourceId,
                'name'             => $journal['source_account_name'],
                'difference'       => '0',
                'difference_float' => 0,
                'in'               => '0',
                'in_float'         => 0,
                'out'              => '0',
                'out_float'        => 0,
                'currency_id'      => (string) $currencyId,
                'currency_code'    => $journal['foreign_currency_code'],
            ];

            // dest next:
            $return[$destKey]   ??= [
                'id'               => (string) $destinationId,
                'name'             => $journal['destination_account_name'],
                'difference'       => '0',
                'difference_float' => 0,
                'in'               => '0',
                'in_float'         => 0,
                'out'              => '0',
                'out_float'        => 0,
                'currency_id'      => (string) $currencyId,
                'currency_code'    => $journal['foreign_currency_code'],
            ];
            // source account? money goes out! (same as above)
            $return[$sourceKey]['out']        = bcadd((string) $return[$sourceKey]['out'], Steam::negative($amount));
            $return[$sourceKey]['difference'] = bcadd($return[$sourceKey]['out'], (string) $return[$sourceKey]['in']);

            // destination  account? money comes in:
            $return[$destKey]['in']           = bcadd((string) $return[$destKey]['in'], $amount);
            $return[$destKey]['difference']   = bcadd((string) $return[$destKey]['out'], $return[$destKey]['in']);
        }

        return $return;
    }
}
