<?php

/*
 * TagController.php
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

namespace FireflyIII\Api\V1\Controllers\Insight\Income;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Insight\GenericRequest;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Http\JsonResponse;

/**
 * Class TagController
 */
class TagController extends Controller
{
    private TagRepositoryInterface $repository;

    /**
     * TagController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $user             = auth()->user();
                $this->repository = app(TagRepositoryInterface::class);
                $this->repository->setUser($user);

                return $next($request);
            }
        );
    }

    /**
     * Expenses for no tag filtered by account.
     */
    public function noTag(GenericRequest $request): JsonResponse
    {
        $accounts         = $request->getAssetAccounts();
        $start            = $request->getStart();
        $end              = $request->getEnd();
        $response         = [];
        $convertToPrimary = Amount::convertToPrimary();
        $primary          = Amount::getPrimaryCurrency();

        // collect all expenses in this period (regardless of type) by the given bills and accounts.
        $collector        = app(GroupCollectorInterface::class);
        $collector->setTypes([TransactionTypeEnum::DEPOSIT->value])->setRange($start, $end)->setDestinationAccounts($accounts);
        $collector->withoutTags();

        $genericSet       = $collector->getExtractedJournals();

        foreach ($genericSet as $journal) {
            // currency
            $currencyId                                = $journal['currency_id'];
            $currencyCode                              = $journal['currency_code'];
            $field                                     = $convertToPrimary && $currencyId !== $primary->id ? 'pc_amount' : 'amount';

            // perhaps use default currency instead?
            if ($convertToPrimary && $journal['currency_id'] !== $primary->id) {
                $currencyId   = $primary->id;
                $currencyCode = $primary->code;
            }
            // use foreign amount when the foreign currency IS the default currency.
            if ($convertToPrimary && $journal['currency_id'] !== $primary->id && $primary->id === $journal['foreign_currency_id']) {
                $field = 'foreign_amount';
            }

            $response[$currencyId] ??= [
                'difference'       => '0',
                'difference_float' => 0,
                'currency_id'      => (string) $currencyId,
                'currency_code'    => $currencyCode,
            ];
            $response[$currencyId]['difference']       = bcadd($response[$currencyId]['difference'], Steam::positive($journal[$field]));
            $response[$currencyId]['difference_float'] = (float) $response[$currencyId]['difference'];

        }

        return response()->json(array_values($response));
    }

    /**
     * Expenses per tag, possibly filtered by tag and account.
     */
    public function tag(GenericRequest $request): JsonResponse
    {
        $accounts   = $request->getAssetAccounts();
        $tags       = $request->getTags();
        $start      = $request->getStart();
        $end        = $request->getEnd();
        $response   = [];

        // get all tags:
        if (0 === $tags->count()) {
            $tags = $this->repository->get();
        }

        // collect all expenses in this period (regardless of type) by the given bills and accounts.
        $collector  = app(GroupCollectorInterface::class);
        $collector->setTypes([TransactionTypeEnum::DEPOSIT->value])->setRange($start, $end)->setDestinationAccounts($accounts);
        $collector->setTags($tags);
        $genericSet = $collector->getExtractedJournals();

        /** @var array $journal */
        foreach ($genericSet as $journal) {
            $currencyId        = (int) $journal['currency_id'];
            $foreignCurrencyId = (int) $journal['foreign_currency_id'];

            /** @var array $tag */
            foreach ($journal['tags'] as $tag) {
                $tagId      = $tag['id'];
                $key        = sprintf('%d-%d', $tagId, $currencyId);
                $foreignKey = sprintf('%d-%d', $tagId, $foreignCurrencyId);

                // on currency ID
                if (0 !== $currencyId) {
                    $response[$key] ??= [
                        'id'               => (string) $tagId,
                        'name'             => $tag['name'],
                        'difference'       => '0',
                        'difference_float' => 0,
                        'currency_id'      => (string) $currencyId,
                        'currency_code'    => $journal['currency_code'],
                    ];
                    $response[$key]['difference']       = bcadd((string) $response[$key]['difference'], Steam::positive($journal['amount']));
                    $response[$key]['difference_float'] = (float) $response[$key]['difference'];
                }

                // on foreign ID
                if (0 !== $foreignCurrencyId) {
                    $response[$foreignKey]                     = $journal[$foreignKey] ?? [
                        'difference'       => '0',
                        'difference_float' => 0,
                        'currency_id'      => (string) $foreignCurrencyId,
                        'currency_code'    => $journal['foreign_currency_code'],
                    ];
                    $response[$foreignKey]['difference']       = bcadd((string) $response[$foreignKey]['difference'], Steam::positive($journal['foreign_amount']));
                    $response[$foreignKey]['difference_float'] = (float) $response[$foreignKey]['difference'];
                }
            }
        }

        return response()->json(array_values($response));
    }
}
