<?php

/**
 * ExecutionController.php
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

namespace FireflyIII\Http\Controllers\RuleGroup;

use Exception;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Http\Requests\SelectTransactionsRequest;
use FireflyIII\Models\RuleGroup;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Class ExecutionController
 */
class ExecutionController extends Controller
{
    /**
     * ExecutionController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware(
            function ($request, $next) {
                app('view')->share('title', (string) trans('firefly.rules'));
                app('view')->share('mainTitleIcon', 'fa-random');


                return $next($request);
            }
        );
    }

    /**
     * Execute the given rulegroup on a set of existing transactions.
     *
     * @throws Exception
     */
    public function execute(SelectTransactionsRequest $request, RuleGroup $ruleGroup): RedirectResponse
    {
        // Get parameters specified by the user
        /** @var User $user */
        $user          = auth()->user();
        $accounts      = implode(',', $request->get('accounts'));
        // create new rule engine:
        $newRuleEngine = app(RuleEngineInterface::class);
        $newRuleEngine->setUser($user);

        // add extra operators:
        $newRuleEngine->addOperator(['type' => 'account_id', 'value' => $accounts]);

        // set rules:
        // #10427, file rule group and not the set of rules.
        $collection    = new Collection()->push($ruleGroup);
        $newRuleEngine->setRuleGroups($collection);
        $newRuleEngine->fire();

        // Tell the user that the job is queued
        session()->flash('success', (string) trans('firefly.applied_rule_group_selection', ['title' => $ruleGroup->title]));

        return redirect()->route('rules.index');
    }

    /**
     * Select transactions to apply the group on.
     *
     * @return Factory|View
     */
    public function selectTransactions(RuleGroup $ruleGroup)
    {
        $subTitle = (string) trans('firefly.apply_rule_group_selection', ['title' => $ruleGroup->title]);

        return view('rules.rule-group.select-transactions', compact('ruleGroup', 'subTitle'));
    }
}
