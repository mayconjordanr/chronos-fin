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

namespace FireflyIII\Repositories\Category;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Category;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Report\Summarizer\TransactionSummarizer;
use FireflyIII\Support\Repositories\UserGroup\UserGroupInterface;
use FireflyIII\Support\Repositories\UserGroup\UserGroupTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class OperationsRepository
 */
class OperationsRepository implements OperationsRepositoryInterface, UserGroupInterface
{
    use UserGroupTrait;

    /**
     * This method returns a list of all the withdrawal transaction journals (as arrays) set in that period
     * which have the specified category set to them. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always negative.
     *
     * First currency, then categories.
     */
    public function listExpenses(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);
        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
            $collector->excludeDestinationAccounts($accounts); // to exclude withdrawals to liabilities.
        }
        if ($categories instanceof Collection && $categories->count() > 0) {
            $collector->setCategories($categories);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $collector->setCategories($this->getCategories());
        }
        $collector->withCategoryInformation()->withAccountInformation()->withBudgetInformation();
        $journals  = $collector->getExtractedJournals();
        $array     = [];

        foreach ($journals as $journal) {
            $currencyId                                                                        = (int) $journal['currency_id'];
            $categoryId                                                                        = (int) $journal['category_id'];
            $categoryName                                                                      = (string) $journal['category_name'];

            // catch "no category" entries.
            if (0 === $categoryId) {
                continue;
            }

            // info about the currency:
            $array[$currencyId]                            ??= [
                'categories'              => [],
                'currency_id'             => (string) $currencyId,
                'currency_name'           => $journal['currency_name'],
                'currency_symbol'         => $journal['currency_symbol'],
                'currency_code'           => $journal['currency_code'],
                'currency_decimal_places' => $journal['currency_decimal_places'],
            ];

            // info about the categories:
            $array[$currencyId]['categories'][$categoryId] ??= [
                'id'                   => (string) $categoryId,
                'name'                 => $categoryName,
                'transaction_journals' => [],
            ];

            // add journal to array:
            // only a subset of the fields.
            $journalId                                                                         = (int) $journal['transaction_journal_id'];
            $array[$currencyId]['categories'][$categoryId]['transaction_journals'][$journalId] = [
                'amount'                   => app('steam')->negative($journal['amount']),
                'date'                     => $journal['date'],
                'source_account_id'        => (string) $journal['source_account_id'],
                'budget_name'              => $journal['budget_name'],
                'source_account_name'      => $journal['source_account_name'],
                'destination_account_id'   => (string) $journal['destination_account_id'],
                'destination_account_name' => $journal['destination_account_name'],
                'description'              => $journal['description'],
                'transaction_group_id'     => (string) $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    /**
     * Returns a list of all the categories belonging to a user.
     */
    private function getCategories(): Collection
    {
        return $this->user->categories()->get();
    }

    /**
     * This method returns a list of all the deposit transaction journals (as arrays) set in that period
     * which have the specified category set to them. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always positive.
     */
    public function listIncome(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionTypeEnum::DEPOSIT->value]);
        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
            $collector->excludeSourceAccounts($accounts); // to prevent income from liabilities.
        }
        if ($categories instanceof Collection && $categories->count() > 0) {
            $collector->setCategories($categories);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $collector->setCategories($this->getCategories());
        }
        $collector->withCategoryInformation()->withAccountInformation();
        $journals  = $collector->getExtractedJournals();
        $array     = [];

        foreach ($journals as $journal) {
            $currencyId                                                                        = (int) $journal['currency_id'];
            $categoryId                                                                        = (int) $journal['category_id'];
            $categoryName                                                                      = (string) $journal['category_name'];

            // catch "no category" entries.
            if (0 === $categoryId) {
                $categoryName = (string) trans('firefly.no_category');
            }

            // info about the currency:
            $array[$currencyId]                            ??= [
                'categories'              => [],
                'currency_id'             => (string) $currencyId,
                'currency_name'           => $journal['currency_name'],
                'currency_symbol'         => $journal['currency_symbol'],
                'currency_code'           => $journal['currency_code'],
                'currency_decimal_places' => $journal['currency_decimal_places'],
            ];

            // info about the categories:
            $array[$currencyId]['categories'][$categoryId] ??= [
                'id'                   => (string) $categoryId,
                'name'                 => $categoryName,
                'transaction_journals' => [],
            ];

            // add journal to array:
            // only a subset of the fields.
            $journalId                                                                         = (int) $journal['transaction_journal_id'];
            $array[$currencyId]['categories'][$categoryId]['transaction_journals'][$journalId] = [
                'amount'                   => app('steam')->positive($journal['amount']),
                'date'                     => $journal['date'],
                'source_account_id'        => (string) $journal['source_account_id'],
                'destination_account_id'   => (string) $journal['destination_account_id'],
                'source_account_name'      => $journal['source_account_name'],
                'destination_account_name' => $journal['destination_account_name'],
                'description'              => $journal['description'],
                'transaction_group_id'     => (string) $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    public function listTransferredIn(Carbon $start, Carbon $end, Collection $accounts, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionTypeEnum::TRANSFER->value])
            ->setDestinationAccounts($accounts)->excludeSourceAccounts($accounts)
        ;
        if ($categories instanceof Collection && $categories->count() > 0) {
            $collector->setCategories($categories);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $collector->setCategories($this->getCategories());
        }
        $collector->withCategoryInformation()->withAccountInformation()->withBudgetInformation();
        $journals  = $collector->getExtractedJournals();
        $array     = [];

        foreach ($journals as $journal) {
            $currencyId                                                                        = (int) $journal['currency_id'];
            $categoryId                                                                        = (int) $journal['category_id'];
            $categoryName                                                                      = (string) $journal['category_name'];

            // catch "no category" entries.
            if (0 === $categoryId) {
                continue;
            }

            // info about the currency:
            $array[$currencyId]                            ??= [
                'categories'              => [],
                'currency_id'             => (string) $currencyId,
                'currency_name'           => $journal['currency_name'],
                'currency_symbol'         => $journal['currency_symbol'],
                'currency_code'           => $journal['currency_code'],
                'currency_decimal_places' => $journal['currency_decimal_places'],
            ];

            // info about the categories:
            $array[$currencyId]['categories'][$categoryId] ??= [
                'id'                   => (string) $categoryId,
                'name'                 => $categoryName,
                'transaction_journals' => [],
            ];

            // add journal to array:
            // only a subset of the fields.
            $journalId                                                                         = (int) $journal['transaction_journal_id'];
            $array[$currencyId]['categories'][$categoryId]['transaction_journals'][$journalId] = [
                'amount'                   => app('steam')->positive($journal['amount']),
                'date'                     => $journal['date'],
                'source_account_id'        => (string) $journal['source_account_id'],
                'category_name'            => $journal['category_name'],
                'source_account_name'      => $journal['source_account_name'],
                'destination_account_id'   => (string) $journal['destination_account_id'],
                'destination_account_name' => $journal['destination_account_name'],
                'description'              => $journal['description'],
                'transaction_group_id'     => (string) $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    public function listTransferredOut(Carbon $start, Carbon $end, Collection $accounts, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionTypeEnum::TRANSFER->value])
            ->setSourceAccounts($accounts)->excludeDestinationAccounts($accounts)
        ;
        if ($categories instanceof Collection && $categories->count() > 0) {
            $collector->setCategories($categories);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $collector->setCategories($this->getCategories());
        }
        $collector->withCategoryInformation()->withAccountInformation()->withBudgetInformation();
        $journals  = $collector->getExtractedJournals();
        $array     = [];

        foreach ($journals as $journal) {
            $currencyId                                                                        = (int) $journal['currency_id'];
            $categoryId                                                                        = (int) $journal['category_id'];
            $categoryName                                                                      = (string) $journal['category_name'];

            // catch "no category" entries.
            if (0 === $categoryId) {
                continue;
            }

            // info about the currency:
            $array[$currencyId]                            ??= [
                'categories'              => [],
                'currency_id'             => (string) $currencyId,
                'currency_name'           => $journal['currency_name'],
                'currency_symbol'         => $journal['currency_symbol'],
                'currency_code'           => $journal['currency_code'],
                'currency_decimal_places' => $journal['currency_decimal_places'],
            ];

            // info about the categories:
            $array[$currencyId]['categories'][$categoryId] ??= [
                'id'                   => (string) $categoryId,
                'name'                 => $categoryName,
                'transaction_journals' => [],
            ];

            // add journal to array:
            // only a subset of the fields.
            $journalId                                                                         = (int) $journal['transaction_journal_id'];
            $array[$currencyId]['categories'][$categoryId]['transaction_journals'][$journalId] = [
                'amount'                   => app('steam')->negative($journal['amount']),
                'date'                     => $journal['date'],
                'source_account_id'        => (string) $journal['source_account_id'],
                'category_name'            => $journal['category_name'],
                'source_account_name'      => $journal['source_account_name'],
                'destination_account_id'   => (string) $journal['destination_account_id'],
                'destination_account_name' => $journal['destination_account_name'],
                'description'              => $journal['description'],
                'transaction_group_id'     => (string) $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    /**
     * Sum of withdrawal journals in period for a set of categories, grouped per currency. Amounts are always negative.
     */
    public function sumExpenses(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector  = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);

        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $categories = $this->getCategories();
        }
        $collector->setCategories($categories);
        $collector->withCategoryInformation();
        $journals   = $collector->getExtractedJournals();
        $summarizer = new TransactionSummarizer($this->user);

        return $summarizer->groupByCurrencyId($journals);
    }

    /**
     * Sum of income journals in period for a set of categories, grouped per currency. Amounts are always positive.
     */
    public function sumIncome(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector        = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::DEPOSIT->value])
        ;

        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $categories = $this->getCategories();
        }
        $collector->setCategories($categories);
        $journals         = $collector->getExtractedJournals();
        $convertToPrimary = Amount::convertToPrimary($this->user);
        $primary          = Amount::getPrimaryCurrency();
        $array            = [];

        foreach ($journals as $journal) {
            // Almost the same as in \FireflyIII\Repositories\Budget\OperationsRepository::sumExpenses
            $amount                    = '0';
            $currencyId                = (int) $journal['currency_id'];
            $currencyName              = $journal['currency_name'];
            $currencySymbol            = $journal['currency_symbol'];
            $currencyCode              = $journal['currency_code'];
            $currencyDecimalPlaces     = $journal['currency_decimal_places'];
            if ($convertToPrimary) {
                $amount = Amount::getAmountFromJournal($journal);
                if ($primary->id !== (int) $journal['currency_id'] && $primary->id !== (int) $journal['foreign_currency_id']) {
                    $currencyId            = $primary->id;
                    $currencyName          = $primary->name;
                    $currencySymbol        = $primary->symbol;
                    $currencyCode          = $primary->code;
                    $currencyDecimalPlaces = $primary->decimal_places;
                }
                if ($primary->id !== (int) $journal['currency_id'] && $primary->id === (int) $journal['foreign_currency_id']) {
                    $currencyId            = $journal['foreign_currency_id'];
                    $currencyName          = $journal['foreign_currency_name'];
                    $currencySymbol        = $journal['foreign_currency_symbol'];
                    $currencyCode          = $journal['foreign_currency_code'];
                    $currencyDecimalPlaces = $journal['foreign_currency_decimal_places'];
                }
                Log::debug(sprintf('[a] Add amount %s %s', $currencyCode, $amount));
            }
            if (!$convertToPrimary) {
                // ignore the amount in foreign currency.
                Log::debug(sprintf('[b] Add amount %s %s', $currencyCode, $journal['amount']));
                $amount = $journal['amount'];
            }

            $array[$currencyId] ??= [
                'sum'                     => '0',
                'currency_id'             => (string) $currencyId,
                'currency_name'           => $currencyName,
                'currency_symbol'         => $currencySymbol,
                'currency_code'           => $currencyCode,
                'currency_decimal_places' => $currencyDecimalPlaces,
            ];
            $array[$currencyId]['sum'] = bcadd($array[$currencyId]['sum'], Steam::positive($amount));
        }

        return $array;
    }

    /**
     * Sum of income journals in period for a set of categories, grouped per currency. Amounts are always positive.
     */
    public function sumTransfers(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::TRANSFER->value])
        ;

        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $categories = $this->getCategories();
        }
        $collector->setCategories($categories);
        $journals  = $collector->getExtractedJournals();
        $array     = [];

        foreach ($journals as $journal) {
            $currencyId                = (int) $journal['currency_id'];
            $array[$currencyId] ??= [
                'sum'                     => '0',
                'currency_id'             => (string) $currencyId,
                'currency_name'           => $journal['currency_name'],
                'currency_symbol'         => $journal['currency_symbol'],
                'currency_code'           => $journal['currency_code'],
                'currency_decimal_places' => $journal['currency_decimal_places'],
            ];
            $array[$currencyId]['sum'] = bcadd($array[$currencyId]['sum'], Steam::positive($journal['amount']));
        }

        return $array;
    }

    public function collectExpenses(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);

        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $categories = $this->getCategories();
        }
        $collector->setCategories($categories);
        $collector->withCategoryInformation();

        return $collector->getExtractedJournals();
    }

    public function collectIncome(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::DEPOSIT->value])
        ;

        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $categories = $this->getCategories();
        }
        $collector->setCategories($categories);

        return $collector->getExtractedJournals();
    }

    public function collectTransfers(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $categories = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)
            ->setTypes([TransactionTypeEnum::TRANSFER->value])
        ;

        if ($accounts instanceof Collection && $accounts->count() > 0) {
            $collector->setAccounts($accounts);
        }
        if (!$categories instanceof Collection || 0 === $categories->count()) {
            $categories = $this->getCategories();
        }
        $collector->setCategories($categories);

        return $collector->getExtractedJournals();
    }

    public function sumCollectedTransactionsByCategory(array $expenses, Category $category, string $method, bool $convertToPrimary = false): array
    {
        Log::debug(sprintf('Start of %s.', __METHOD__));
        $summarizer = new TransactionSummarizer($this->user);
        $summarizer->setConvertToPrimary($convertToPrimary);

        // filter $journals by range AND currency if it is present.
        $expenses   = array_filter($expenses, static function (array $expense) use ($category): bool {
            return $expense['category_id'] === $category->id;
        });

        return $summarizer->groupByCurrencyId($expenses, $method, false);
    }
}
