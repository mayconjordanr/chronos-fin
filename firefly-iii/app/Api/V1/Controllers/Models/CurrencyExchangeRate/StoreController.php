<?php

/*
 * StoreController.php
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

namespace FireflyIII\Api\V1\Controllers\Models\CurrencyExchangeRate;

use Carbon\Carbon;
use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Api\V1\Requests\Models\CurrencyExchangeRate\StoreByCurrenciesRequest;
use FireflyIII\Api\V1\Requests\Models\CurrencyExchangeRate\StoreByDateRequest;
use FireflyIII\Api\V1\Requests\Models\CurrencyExchangeRate\StoreRequest;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\ExchangeRate\ExchangeRateRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Http\Api\ValidatesUserGroupTrait;
use FireflyIII\Transformers\ExchangeRateTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class StoreController extends Controller
{
    use ValidatesUserGroupTrait;

    public const string RESOURCE_KEY                       = 'exchange-rates';
    protected array                         $acceptedRoles = [UserRoleEnum::OWNER];
    private ExchangeRateRepositoryInterface $repository;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(
            function ($request, $next) {
                $this->repository = app(ExchangeRateRepositoryInterface::class);
                $this->repository->setUserGroup($this->validateUserGroup($request));

                return $next($request);
            }
        );
    }

    public function storeByCurrencies(StoreByCurrenciesRequest $request, TransactionCurrency $from, TransactionCurrency $to): JsonResponse
    {

        $data        = $request->getAll();
        $collection  = new Collection();

        foreach ($data as $date => $rate) {
            $date     = Carbon::createFromFormat('Y-m-d', $date);
            $existing = $this->repository->getSpecificRateOnDate($from, $to, $date);
            if ($existing instanceof CurrencyExchangeRate) {
                // update existing rate.
                $existing = $this->repository->updateExchangeRate($existing, $rate);
                $collection->push($existing);

                continue;
            }
            $new      = $this->repository->storeExchangeRate($from, $to, $rate, $date);
            $collection->push($new);
        }

        $count       = $collection->count();
        $paginator   = new LengthAwarePaginator($collection, $count, $count, 1);
        $transformer = new ExchangeRateTransformer();
        $transformer->setParameters($this->parameters); // give params to transformer

        return response()
            ->json($this->jsonApiList(self::RESOURCE_KEY, $paginator, $transformer))
            ->header('Content-Type', self::CONTENT_TYPE)
        ;
    }

    public function storeByDate(StoreByDateRequest $request, Carbon $date): JsonResponse
    {

        $data        = $request->getAll();
        $from        = $request->getFromCurrency();
        $collection  = new Collection();
        foreach ($data['rates'] as $key => $rate) {
            $to       = Amount::getTransactionCurrencyByCode($key);
            $existing = $this->repository->getSpecificRateOnDate($from, $to, $date);
            if ($existing instanceof CurrencyExchangeRate) {
                // update existing rate.
                $existing = $this->repository->updateExchangeRate($existing, $rate);
                $collection->push($existing);

                continue;
            }
            $new      = $this->repository->storeExchangeRate($from, $to, $rate, $date);
            $collection->push($new);
        }

        $count       = $collection->count();
        $paginator   = new LengthAwarePaginator($collection, $count, $count, 1);
        $transformer = new ExchangeRateTransformer();
        $transformer->setParameters($this->parameters); // give params to transformer

        return response()
            ->json($this->jsonApiList(self::RESOURCE_KEY, $paginator, $transformer))
            ->header('Content-Type', self::CONTENT_TYPE)
        ;
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $date        = $request->getDate();
        $rate        = $request->getRate();
        $from        = $request->getFromCurrency();
        $to          = $request->getToCurrency();

        // already has rate?
        $object      = $this->repository->getSpecificRateOnDate($from, $to, $date);
        if ($object instanceof CurrencyExchangeRate) {
            // just update it, no matter.
            $rate = $this->repository->updateExchangeRate($object, $rate, $date);
        }
        if (!$object instanceof CurrencyExchangeRate) {
            // store new
            $rate = $this->repository->storeExchangeRate($from, $to, $rate, $date);
        }

        $transformer = new ExchangeRateTransformer();
        $transformer->setParameters($this->parameters);

        return response()
            ->api($this->jsonApiObject(self::RESOURCE_KEY, $rate, $transformer))
            ->header('Content-Type', self::CONTENT_TYPE)
        ;
    }
}
