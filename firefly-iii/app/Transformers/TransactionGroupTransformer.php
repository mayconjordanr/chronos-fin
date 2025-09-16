<?php

/**
 * TransactionGroupTransformer.php
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

namespace FireflyIII\Transformers;

use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\Location;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\NullArrayObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class TransactionGroupTransformer
 */
class TransactionGroupTransformer extends AbstractTransformer
{
    private readonly TransactionGroupRepositoryInterface $groupRepos;
    private readonly array                               $metaDateFields;
    private readonly array                               $metaFields;

    /**
     * Constructor.
     */
    public function __construct()
    {
        Log::debug('TransactionGroupTransformer constructor.');
        $this->groupRepos     = app(TransactionGroupRepositoryInterface::class);
        $this->metaFields     = [
            'sepa_cc',
            'sepa_ct_op',
            'sepa_ct_id',
            'sepa_db',
            'sepa_country',
            'sepa_ep',
            'sepa_ci',
            'sepa_batch_id',
            'internal_reference',
            'bunq_payment_id',
            'import_hash_v2',
            'recurrence_id',
            'external_id',
            'original_source',
            'external_url',
            'recurrence_count',
            'recurrence_total',
        ];
        $this->metaDateFields = ['interest_date', 'book_date', 'process_date', 'due_date', 'payment_date', 'invoice_date'];
    }

    public function transform(array $group): array
    {
        $data  = new NullArrayObject($group);
        $first = new NullArrayObject(reset($group['transactions']));

        return [
            'id'           => (int) $first['transaction_group_id'],
            'created_at'   => $first['created_at']->toAtomString(),
            'updated_at'   => $first['updated_at']->toAtomString(),
            'user'         => (string) $data['user_id'],
            'user_group'   => (string) $data['user_group_id'],
            'group_title'  => $data['title'],
            'transactions' => $this->transformTransactions($data),
            'links'        => [
                [
                    'rel' => 'self',
                    'uri' => '/transactions/'.$first['transaction_group_id'],
                ],
            ],
        ];
    }

    private function transformTransactions(NullArrayObject $data): array
    {
        $result       = [];
        $transactions = $data['transactions'] ?? [];
        foreach ($transactions as $transaction) {
            $result[] = $this->transformTransaction($transaction);
        }

        return $result;
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function transformTransaction(array $transaction): array
    {
        // amount:
        $amount          = Steam::positive((string) ($transaction['amount'] ?? '0'));
        $foreignAmount   = null;
        if (null !== $transaction['foreign_amount'] && '' !== $transaction['foreign_amount'] && 0 !== bccomp('0', (string) $transaction['foreign_amount'])) {
            $foreignAmount = Steam::positive($transaction['foreign_amount']);
        }

        // set primary amount to the normal amount if the currency matches.
        if ($transaction['primary_currency']['id'] ?? null === $transaction['currency_id']) {
            $transaction['pc_amount'] = $amount;
        }

        if (array_key_exists('pc_amount', $transaction) && null !== $transaction['pc_amount']) {
            $transaction['pc_amount'] = Steam::positive($transaction['pc_amount']);
        }
        $type            = $this->stringFromArray($transaction, 'transaction_type_type', TransactionTypeEnum::WITHDRAWAL->value);

        // must be 0 (int) or NULL
        $recurrenceTotal = $transaction['meta']['recurrence_total'] ?? null;
        $recurrenceTotal = null !== $recurrenceTotal ? (int) $recurrenceTotal : null;
        $recurrenceCount = $transaction['meta']['recurrence_count'] ?? null;
        $recurrenceCount = null !== $recurrenceCount ? (int) $recurrenceCount : null;

        return [
            'user'                            => (string) $transaction['user_id'],
            'transaction_journal_id'          => (string) $transaction['transaction_journal_id'],
            'type'                            => strtolower((string) $type),
            'date'                            => $transaction['date']->toAtomString(),
            'order'                           => $transaction['order'],

            // currency information, structured for 6.3.0.
            'object_has_currency_setting'     => true,

            'currency_id'                     => (string) $transaction['currency_id'],
            'currency_code'                   => $transaction['currency_code'],
            'currency_name'                   => $transaction['currency_name'],
            'currency_symbol'                 => $transaction['currency_symbol'],
            'currency_decimal_places'         => (int) $transaction['currency_decimal_places'],

            'foreign_currency_id'             => $this->stringFromArray($transaction, 'foreign_currency_id', null),
            'foreign_currency_code'           => $transaction['foreign_currency_code'],
            'foreign_currency_name'           => $transaction['foreign_currency_name'],
            'foreign_currency_symbol'         => $transaction['foreign_currency_symbol'],
            'foreign_currency_decimal_places' => $transaction['foreign_currency_decimal_places'],

            'primary_currency_id'             => $transaction['primary_currency']['id'] ?? null,
            'primary_currency_code'           => $transaction['primary_currency']['code'] ?? null,
            'primary_currency_name'           => $transaction['primary_currency']['name'] ?? null,
            'primary_currency_symbol'         => $transaction['primary_currency']['symbol'] ?? null,
            'primary_currency_decimal_places' => $transaction['primary_currency']['decimal_places'] ?? null,

            // amounts, structured for 6.3.0.
            'amount'                          => $amount,
            'pc_amount'                       => $transaction['pc_amount'] ?? null,

            'foreign_amount'                  => $foreignAmount,
            'pc_foreign_amount'               => null,

            'source_balance_after'            => $transaction['source_balance_after'] ?? null,
            'pc_source_balance_after'         => null,

            // destination balance after
            'destination_balance_after'       => $transaction['destination_balance_after'] ?? null,
            'pc_destination_balance_after'    => null,

            'source_balance_dirty'            => $transaction['source_balance_dirty'],
            'destination_balance_dirty'       => $transaction['destination_balance_dirty'],

            'description'                     => $transaction['description'],

            'source_id'                       => (string) $transaction['source_account_id'],
            'source_name'                     => $transaction['source_account_name'],
            'source_iban'                     => $transaction['source_account_iban'],
            'source_type'                     => $transaction['source_account_type'],

            'destination_id'                  => (string) $transaction['destination_account_id'],
            'destination_name'                => $transaction['destination_account_name'],
            'destination_iban'                => $transaction['destination_account_iban'],
            'destination_type'                => $transaction['destination_account_type'],

            'budget_id'                       => $this->stringFromArray($transaction, 'budget_id', null),
            'budget_name'                     => $transaction['budget_name'],

            'category_id'                     => $this->stringFromArray($transaction, 'category_id', null),
            'category_name'                   => $transaction['category_name'],

            'bill_id'                         => $this->stringFromArray($transaction, 'bill_id', null),
            'bill_name'                       => $transaction['bill_name'],
            'subscription_id'                 => $this->stringFromArray($transaction, 'bill_id', null),
            'subscription_name'               => $transaction['bill_name'],

            'reconciled'                      => $transaction['reconciled'],
            'notes'                           => $transaction['notes'],
            'tags'                            => $transaction['tags'],

            'internal_reference'              => $transaction['meta']['internal_reference'] ?? null,
            'external_id'                     => $transaction['meta']['external_id'] ?? null,
            'original_source'                 => $transaction['meta']['original_source'] ?? null,
            'recurrence_id'                   => $transaction['meta']['recurrence_id'] ?? null,
            'recurrence_total'                => $recurrenceTotal,
            'recurrence_count'                => $recurrenceCount,
            'external_url'                    => $transaction['meta']['external_url'] ?? null,
            'import_hash_v2'                  => $transaction['meta']['import_hash_v2'] ?? null,

            'sepa_cc'                         => $transaction['meta']['sepa_cc'] ?? null,
            'sepa_ct_op'                      => $transaction['meta']['sepa_ct_op'] ?? null,
            'sepa_ct_id'                      => $transaction['meta']['sepa_ct_id'] ?? null,
            'sepa_db'                         => $transaction['meta']['sepa_db'] ?? null,
            'sepa_country'                    => $transaction['meta']['sepa_country'] ?? null,
            'sepa_ep'                         => $transaction['meta']['sepa_ep'] ?? null,
            'sepa_ci'                         => $transaction['meta']['sepa_ci'] ?? null,
            'sepa_batch_id'                   => $transaction['meta']['sepa_batch_id'] ?? null,

            'interest_date'                   => array_key_exists('interest_date', $transaction['meta_date']) ? $transaction['meta_date']['interest_date']->toW3CString() : null,
            'book_date'                       => array_key_exists('book_date', $transaction['meta_date']) ? $transaction['meta_date']['book_date']->toW3CString() : null,
            'process_date'                    => array_key_exists('process_date', $transaction['meta_date']) ? $transaction['meta_date']['process_date']->toW3CString() : null,
            'due_date'                        => array_key_exists('due_date', $transaction['meta_date']) ? $transaction['meta_date']['due_date']->toW3CString() : null,
            'payment_date'                    => array_key_exists('payment_date', $transaction['meta_date']) ? $transaction['meta_date']['payment_date']->toW3CString() : null,
            'invoice_date'                    => array_key_exists('invoice_date', $transaction['meta_date']) ? $transaction['meta_date']['invoice_date']->toW3CString() : null,

            // location data
            'longitude'                       => $transaction['location']['longitude'],
            'latitude'                        => $transaction['location']['latitude'],
            'zoom_level'                      => $transaction['location']['zoom_level'],
            'has_attachments'                 => $transaction['attachment_count'] > 0,
        ];
    }

    private function stringFromArray(array $array, string $key, ?string $default): ?string
    {
        if (array_key_exists($key, $array) && null === $array[$key]) {
            return null;
        }
        if (array_key_exists($key, $array) && null !== $array[$key]) {
            if (0 === $array[$key]) {
                return $default;
            }
            if ('0' === $array[$key]) {
                return $default;
            }

            return (string) $array[$key];
        }

        if (null !== $default) {
            return $default;
        }

        return null;
    }

    /**
     * @throws FireflyException
     */
    public function transformObject(TransactionGroup $group): array
    {
        try {
            $result = [
                'id'           => $group->id,
                'created_at'   => $group->created_at->toAtomString(),
                'updated_at'   => $group->updated_at->toAtomString(),
                'user'         => $group->user_id,
                'group_title'  => $group->title,
                'transactions' => $this->transformJournals($group->transactionJournals),
                'links'        => [
                    [
                        'rel' => 'self',
                        'uri' => '/transactions/'.$group->id,
                    ],
                ],
            ];
        } catch (FireflyException $e) {
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());

            throw new FireflyException(sprintf('Transaction group #%d is broken. Please check out your log files.', $group->id), 0, $e);
        }

        // do something else.

        return $result;
    }

    /**
     * @throws FireflyException
     */
    private function transformJournals(Collection $transactionJournals): array
    {
        $result = [];

        /** @var TransactionJournal $journal */
        foreach ($transactionJournals as $journal) {
            $result[] = $this->transformJournal($journal);
        }

        return $result;
    }

    /**
     * @throws FireflyException
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function transformJournal(TransactionJournal $journal): array
    {
        $source          = $this->getSourceTransaction($journal);
        $destination     = $this->getDestinationTransaction($journal);
        $type            = $journal->transactionType->type;
        $currency        = $source->transactionCurrency;
        $amount          = Steam::bcround($this->getAmount($source->amount), $currency->decimal_places ?? 0);
        $foreignAmount   = $this->getForeignAmount($source->foreign_amount ?? null);
        $metaFieldData   = $this->groupRepos->getMetaFields($journal->id, $this->metaFields);
        $metaDates       = $this->getDates($this->groupRepos->getMetaDateFields($journal->id, $this->metaDateFields));
        $foreignCurrency = $this->getForeignCurrency($source->foreignCurrency);
        $budget          = $this->getBudget($journal->budgets->first());
        $category        = $this->getCategory($journal->categories->first());
        $bill            = $this->getBill($journal->bill);

        if (null !== $foreignAmount && null !== $source->foreignCurrency) {
            $foreignAmount = Steam::bcround($foreignAmount, $foreignCurrency['decimal_places'] ?? 0);
        }

        $longitude       = null;
        $latitude        = null;
        $zoomLevel       = null;
        $location        = $this->getLocation($journal);
        if ($location instanceof Location) {
            $longitude = $location->longitude;
            $latitude  = $location->latitude;
            $zoomLevel = $location->zoom_level;
        }

        return [
            'user'                            => $journal->user_id,
            'transaction_journal_id'          => (string) $journal->id,
            'type'                            => strtolower((string) $type),
            'date'                            => $journal->date->toAtomString(),
            'order'                           => $journal->order,

            'currency_id'                     => (string) $currency->id,
            'currency_code'                   => $currency->code,
            'currency_symbol'                 => $currency->symbol,
            'currency_decimal_places'         => $currency->decimal_places,

            'foreign_currency_id'             => (string) $foreignCurrency['id'],
            'foreign_currency_code'           => $foreignCurrency['code'],
            'foreign_currency_symbol'         => $foreignCurrency['symbol'],
            'foreign_currency_decimal_places' => $foreignCurrency['decimal_places'],

            'amount'                          => Steam::bcround($amount, $currency->decimal_places),
            'foreign_amount'                  => $foreignAmount,

            'description'                     => $journal->description,

            'source_id'                       => (string) $source->account_id,
            'source_name'                     => $source->account->name,
            'source_iban'                     => $source->account->iban,
            'source_type'                     => $source->account->accountType->type,

            'destination_id'                  => (string) $destination->account_id,
            'destination_name'                => $destination->account->name,
            'destination_iban'                => $destination->account->iban,
            'destination_type'                => $destination->account->accountType->type,

            'budget_id'                       => (string) $budget['id'],
            'budget_name'                     => $budget['name'],

            'category_id'                     => (string) $category['id'],
            'category_name'                   => $category['name'],

            'bill_id'                         => (string) $bill['id'],
            'bill_name'                       => $bill['name'],

            'reconciled'                      => $source->reconciled,
            'notes'                           => $this->groupRepos->getNoteText($journal->id),
            'tags'                            => $this->groupRepos->getTags($journal->id),

            'internal_reference'              => $metaFieldData['internal_reference'],
            'external_id'                     => $metaFieldData['external_id'],
            'original_source'                 => $metaFieldData['original_source'],
            'recurrence_id'                   => $metaFieldData['recurrence_id'],
            'bunq_payment_id'                 => $metaFieldData['bunq_payment_id'],
            'import_hash_v2'                  => $metaFieldData['import_hash_v2'],

            'sepa_cc'                         => $metaFieldData['sepa_cc'],
            'sepa_ct_op'                      => $metaFieldData['sepa_ct_op'],
            'sepa_ct_id'                      => $metaFieldData['sepa_ct_id'],
            'sepa_db'                         => $metaFieldData['sepa_db'],
            'sepa_country'                    => $metaFieldData['sepa_country'],
            'sepa_ep'                         => $metaFieldData['sepa_ep'],
            'sepa_ci'                         => $metaFieldData['sepa_ci'],
            'sepa_batch_id'                   => $metaFieldData['sepa_batch_id'],

            'interest_date'                   => $metaDates['interest_date'],
            'book_date'                       => $metaDates['book_date'],
            'process_date'                    => $metaDates['process_date'],
            'due_date'                        => $metaDates['due_date'],
            'payment_date'                    => $metaDates['payment_date'],
            'invoice_date'                    => $metaDates['invoice_date'],

            // location data
            'longitude'                       => $longitude,
            'latitude'                        => $latitude,
            'zoom_level'                      => $zoomLevel,
        ];
    }

    /**
     * @throws FireflyException
     */
    private function getSourceTransaction(TransactionJournal $journal): Transaction
    {
        $result = $journal->transactions->first(
            static function (Transaction $transaction) {
                return (float) $transaction->amount < 0; // lame but it works.
            }
        );
        if (null === $result) {
            throw new FireflyException(sprintf('Journal #%d unexpectedly has no source transaction.', $journal->id));
        }

        return $result;
    }

    /**
     * @throws FireflyException
     */
    private function getDestinationTransaction(TransactionJournal $journal): Transaction
    {
        $result = $journal->transactions->first(
            static function (Transaction $transaction) {
                return (float) $transaction->amount > 0; // lame but it works
            }
        );
        if (null === $result) {
            throw new FireflyException(sprintf('Journal #%d unexpectedly has no destination transaction.', $journal->id));
        }

        return $result;
    }

    private function getAmount(string $amount): string
    {
        return Steam::positive($amount);
    }

    private function getForeignAmount(?string $foreignAmount): ?string
    {
        if (null !== $foreignAmount && '' !== $foreignAmount && 0 !== bccomp('0', $foreignAmount)) {
            return Steam::positive($foreignAmount);
        }

        return null;
    }

    private function getDates(NullArrayObject $dates): array
    {
        $fields = [
            'interest_date',
            'book_date',
            'process_date',
            'due_date',
            'payment_date',
            'invoice_date',
        ];
        $return = [];
        foreach ($fields as $field) {
            $return[$field] = null;
            if (null !== $dates[$field]) {
                $return[$field] = $dates[$field]->toAtomString();
            }
        }

        return $return;
    }

    private function getForeignCurrency(?TransactionCurrency $currency): array
    {
        $array                   = [
            'id'             => null,
            'code'           => null,
            'symbol'         => null,
            'decimal_places' => null,
        ];
        if (!$currency instanceof TransactionCurrency) {
            return $array;
        }
        $array['id']             = $currency->id;
        $array['code']           = $currency->code;
        $array['symbol']         = $currency->symbol;
        $array['decimal_places'] = $currency->decimal_places;

        return $array;
    }

    private function getBudget(?Budget $budget): array
    {
        $array         = [
            'id'   => null,
            'name' => null,
        ];
        if (!$budget instanceof Budget) {
            return $array;
        }
        $array['id']   = $budget->id;
        $array['name'] = $budget->name;

        return $array;
    }

    private function getCategory(?Category $category): array
    {
        $array         = [
            'id'   => null,
            'name' => null,
        ];
        if (!$category instanceof Category) {
            return $array;
        }
        $array['id']   = $category->id;
        $array['name'] = $category->name;

        return $array;
    }

    private function getBill(?Bill $bill): array
    {
        $array         = [
            'id'   => null,
            'name' => null,
        ];
        if (!$bill instanceof Bill) {
            return $array;
        }
        $array['id']   = (string) $bill->id;
        $array['name'] = $bill->name;

        return $array;
    }

    private function getLocation(TransactionJournal $journal): ?Location
    {
        /** @var null|Location */
        return $journal->locations()->first();
    }
}
