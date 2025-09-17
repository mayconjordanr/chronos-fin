<?php

/**
 * ShowController.php
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

namespace FireflyIII\Http\Controllers\Account;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Debug\Timer;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Http\Controllers\PeriodOverview;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class ShowController
 */
class ShowController extends Controller
{
    use PeriodOverview;

    private AccountRepositoryInterface $repository;

    /**
     * ShowController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        app('view')->share('showCategory', true);

        // translations:
        $this->middleware(
            function ($request, $next) {
                app('view')->share('mainTitleIcon', 'fa-credit-card');
                app('view')->share('title', (string) trans('firefly.accounts'));

                $this->repository = app(AccountRepositoryInterface::class);

                return $next($request);
            }
        );
    }

    /**
     * Show an account.
     *
     * @return Factory|Redirector|RedirectResponse|View
     *
     * @throws FireflyException
     *                                              */
    public function show(Request $request, Account $account, ?Carbon $start = null, ?Carbon $end = null)
    {
        if (0 === $account->id) {
            throw new NotFoundHttpException();
        }
        $objectType       = config(sprintf('firefly.shortNamesByFullName.%s', $account->accountType->type));

        if (!$this->isEditableAccount($account)) {
            return $this->redirectAccountToAccount($account);
        }

        $start ??= session('start');
        $end   ??= session('end');

        /** @var Carbon $start */
        /** @var Carbon $end */
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        // make sure dates are end of day and start of day:
        $start->startOfDay();
        $end->endOfDay();

        $location         = $this->repository->getLocation($account);
        $attachments      = $this->repository->getAttachments($account);
        $today            = today(config('app.timezone'));
        $subTitleIcon     = config(sprintf('firefly.subIconsByIdentifier.%s', $account->accountType->type));
        $page             = (int) $request->get('page');
        $pageSize         = (int) app('preferences')->get('listPageSize', 50)->data;
        $accountCurrency  = $this->repository->getAccountCurrency($account);
        $currency         = $accountCurrency ?? $this->primaryCurrency;
        $fStart           = $start->isoFormat($this->monthAndDayFormat);
        $fEnd             = $end->isoFormat($this->monthAndDayFormat);
        $subTitle         = (string) trans('firefly.journals_in_period_for_account', ['name' => $account->name, 'start' => $fStart, 'end' => $fEnd]);
        $chartUrl         = route('chart.account.period', [$account->id, $start->format('Y-m-d'), $end->format('Y-m-d')]);
        $firstTransaction = $this->repository->oldestJournalDate($account) ?? $start;

        // go back max 3 years.
        $threeYearsAgo    = clone $start;
        $threeYearsAgo->startOfYear()->subYears(3);
        if ($firstTransaction->lt($threeYearsAgo)) {
            $firstTransaction = clone $threeYearsAgo;
        }

        Log::debug('Start period overview');
        $timer            = Timer::getInstance();
        $timer->start('period-overview');
        $periods          = $this->getAccountPeriodOverview($account, $firstTransaction, $end);

        Log::debug('End period overview');
        $timer->stop('period-overview');

        // if layout = v2, overrule the page title.
        if ('v1' !== config('view.layout')) {
            $subTitle = (string) trans('firefly.all_journals_for_account', ['name' => $account->name]);
        }
        Log::debug('Collect transactions');
        $timer->start('collection');

        /** @var GroupCollectorInterface $collector */
        $collector        = app(GroupCollectorInterface::class);
        $collector
            ->setAccounts(new Collection()->push($account))
            ->setLimit($pageSize)
            ->setPage($page)
            ->withAttachmentInformation()
            ->withAPIInformation()
            ->setRange($start, $end)
        ;
        // this search will not include transaction groups where this asset account (or liability)
        // is just part of ONE of the journals. To force this:
        $collector->setExpandGroupSearch(true);
        $groups           = $collector->getPaginatedGroups();


        Log::debug('End collect transactions');
        $timer->stop('collection');
        $groups->setPath(route('accounts.show', [$account->id, $start->format('Y-m-d'), $end->format('Y-m-d')]));
        $showAll          = false;
        $now              = today()->endOfDay();
        if ($now->gt($end) || $now->lt($start)) {
            $now = $end;
        }

        Log::debug(sprintf('show: Call finalAccountBalance with date/time "%s"', $now->toIso8601String()));
        $balances         = Steam::filterAccountBalance(Steam::finalAccountBalance($account, $now), $account, $this->convertToPrimary, $accountCurrency);

        return view(
            'accounts.show',
            compact(
                'account',
                'showAll',
                'objectType',
                'currency',
                'today',
                'periods',
                'subTitleIcon',
                'groups',
                'attachments',
                'subTitle',
                'start',
                'end',
                'chartUrl',
                'location',
                'balances'
            )
        );
    }

    /**
     * Show an account.
     *
     * @return Factory|Redirector|RedirectResponse|View
     *
     * @throws FireflyException
     *                                              */
    public function showAll(Request $request, Account $account)
    {
        if (!$this->isEditableAccount($account)) {
            return $this->redirectAccountToAccount($account);
        }
        $location        = $this->repository->getLocation($account);
        $isLiability     = $this->repository->isLiability($account);
        $attachments     = $this->repository->getAttachments($account);
        $objectType      = config(sprintf('firefly.shortNamesByFullName.%s', $account->accountType->type));
        $end             = today(config('app.timezone'));
        $today           = today(config('app.timezone'));
        $accountCurrency = $this->repository->getAccountCurrency($account);
        $start           = $this->repository->oldestJournalDate($account) ?? today(config('app.timezone'))->startOfMonth();
        $subTitleIcon    = config('firefly.subIconsByIdentifier.'.$account->accountType->type);
        $page            = (int) $request->get('page');
        $pageSize        = (int) app('preferences')->get('listPageSize', 50)->data;
        $currency        = $this->repository->getAccountCurrency($account) ?? $this->primaryCurrency;
        $subTitle        = (string) trans('firefly.all_journals_for_account', ['name' => $account->name]);
        $periods         = new Collection();

        $end->endOfDay();

        /** @var GroupCollectorInterface $collector */
        $collector       = app(GroupCollectorInterface::class);
        $collector->setAccounts(new Collection()->push($account))->setLimit($pageSize)->setPage($page)->withAccountInformation()->withCategoryInformation();

        // this search will not include transaction groups where this asset account (or liability)
        // is just part of ONE of the journals. To force this:
        $collector->setExpandGroupSearch(true);

        $groups          = $collector->getPaginatedGroups();
        $groups->setPath(route('accounts.show.all', [$account->id]));
        $chartUrl        = route('chart.account.period', [$account->id, $start->format('Y-m-d'), $end->format('Y-m-d')]);
        $showAll         = true;
        // correct
        Log::debug(sprintf('showAll: Call finalAccountBalance with date/time "%s"', $end->toIso8601String()));
        $balances        = Steam::filterAccountBalance(Steam::finalAccountBalance($account, $end), $account, $this->convertToPrimary, $accountCurrency);

        return view(
            'accounts.show',
            compact(
                'account',
                'showAll',
                'location',
                'objectType',
                'isLiability',
                'attachments',
                'currency',
                'today',
                'chartUrl',
                'periods',
                'subTitleIcon',
                'groups',
                'subTitle',
                'start',
                'end',
                'balances'
            )
        );
    }
}
