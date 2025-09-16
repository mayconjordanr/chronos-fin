<?php

/*
 * IndexController.php
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

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Repositories\ExchangeRate\ExchangeRateRepositoryInterface;
use FireflyIII\Support\Http\Api\ValidatesUserGroupTrait;
use FireflyIII\Transformers\ExchangeRateTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Class ShowController
 */
class IndexController extends Controller
{
    use ValidatesUserGroupTrait;

    public const string RESOURCE_KEY = 'currency_exchange_rates';
    protected array $acceptedRoles   = [UserRoleEnum::OWNER];
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

    public function index(): JsonResponse
    {
        $entries     = $this->repository->getAll();
        $pageSize    = $this->parameters->get('limit');
        $count       = $entries->count();
        $entries     = $entries->slice(($this->parameters->get('page') - 1) * $pageSize, $pageSize);
        $paginator   = new LengthAwarePaginator($entries, $count, $pageSize, $this->parameters->get('page'));
        $transformer = new ExchangeRateTransformer();
        $transformer->setParameters($this->parameters); // give params to transformer

        return response()
            ->json($this->jsonApiList(self::RESOURCE_KEY, $paginator, $transformer))
            ->header('Content-Type', self::CONTENT_TYPE)
        ;
    }
}
