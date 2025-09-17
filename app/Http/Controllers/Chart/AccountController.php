<?php

/**
 * AccountController.php
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

namespace FireflyIII\Http\Controllers\Chart;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Generator\Chart\Basic\GeneratorInterface;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Support\CacheProperties;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Http\Api\ExchangeRateConverter;
use FireflyIII\Support\Http\Controllers\AugumentData;
use FireflyIII\Support\Http\Controllers\ChartGeneration;
use FireflyIII\Support\Http\Controllers\DateCalculation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

use function Safe\json_encode;

/**
 * Class AccountController.
 */
class AccountController extends Controller
{
    use AugumentData;
    use ChartGeneration;
    use DateCalculation;

    protected GeneratorInterface        $generator;
    private AccountRepositoryInterface  $accountRepository;
    private CurrencyRepositoryInterface $currencyRepository;

    /**
     * AccountController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware(
            function ($request, $next) {
                $this->generator          = app(GeneratorInterface::class);
                $this->accountRepository  = app(AccountRepositoryInterface::class);
                $this->currencyRepository = app(CurrencyRepositoryInterface::class);

                return $next($request);
            }
        );
    }

    /**
     * Shows the balances for all the user's expense accounts (on the front page).
     * 2025-08-06 validated for multi (primary) currency
     *
     * This chart is (multi) currency aware.
     */
    public function expenseAccounts(): JsonResponse
    {
        Log::debug('ExpenseAccounts');

        /** @var Carbon $start */
        $start         = clone session('start', today(config('app.timezone'))->startOfMonth());

        /** @var Carbon $end */
        $end           = clone session('end', today(config('app.timezone'))->endOfMonth());
        $start->startOfDay();
        $end->endOfDay();

        $cache         = new CacheProperties();
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty($this->convertToPrimary);
        $cache->addProperty('chart.account.expense-accounts');
        if ($cache->has()) {
            return response()->json($cache->get());
        }

        // prep some vars:
        $currencies    = [];
        $chartData     = [];
        $tempData      = [];

        // grab all accounts and names
        $accounts      = $this->accountRepository->getAccountsByType([AccountTypeEnum::EXPENSE->value]);
        $accountNames  = $this->extractNames($accounts);

        // grab all balances
        Log::debug(sprintf('expenseAccounts: accountsBalancesOptimized("%s")', $start->format('Y-m-d H:i:s')));
        Log::debug(sprintf('expenseAccounts: accountsBalancesOptimized("%s")', $end->format('Y-m-d H:i:s')));
        $startBalances = Steam::accountsBalancesOptimized($accounts, $start, $this->primaryCurrency, $this->convertToPrimary);
        $endBalances   = Steam::accountsBalancesOptimized($accounts, $end, $this->primaryCurrency, $this->convertToPrimary);
        Log::debug('Done collecting balances');
        // loop the accounts, then check for balance and currency info.
        foreach ($accounts as $account) {
            // Log::debug(sprintf('[a] Now in account #%d ("%s")', $account->id, $account->name));
            $expenses = $endBalances[$account->id] ?? false;
            if (false === $expenses) {
                Log::error(sprintf('Found no end balance for account #%d', $account->id));

                continue;
            }

            /**
             * @var string $key
             * @var string $endBalance
             */
            foreach ($expenses as $key => $endBalance) {
                if (!$this->convertToPrimary && 'pc_balance' === $key) {
                    //                    Log::debug(sprintf('[a] Will skip expense array "%s"', $key));

                    continue;
                }
                if ($this->convertToPrimary && 'pc_balance' !== $key) {
                    //                    Log::debug(sprintf('[b] Will skip expense array "%s"', $key));

                    continue;
                }
                // Log::debug(sprintf('Will process expense array "%s" with amount %s', $key, $endBalance));
                $searchCode   = $this->convertToPrimary ? $this->primaryCurrency->code : $key;
                $searchCode   = 'balance' === $searchCode || 'pc_balance' === $searchCode ? $this->primaryCurrency->code : $searchCode;
                // Log::debug(sprintf('Search code is %s', $searchCode));
                // see if there is an accompanying start amount.
                // grab the difference and find the currency.
                $startBalance = ($startBalances[$account->id][$key] ?? '0');
                //                Log::debug(sprintf('Start balance is %s', $startBalance));
                $diff         = bcsub($endBalance, $startBalance);
                $currencies[$searchCode] ??= $this->currencyRepository->findByCode($searchCode);
                if (0 !== bccomp($diff, '0')) {
                    // store the values in a temporary array.
                    $tempData[] = [
                        'name'        => $accountNames[$account->id],
                        'difference'  => $diff,
                        'diff_float'  => (float)$diff, // intentional float
                        'currency_id' => $currencies[$searchCode]->id,
                    ];
                }
            }
        }
        // recreate currencies, but on ID instead of code.
        $newCurrencies = [];
        foreach ($currencies as $currency) {
            $newCurrencies[$currency->id] = $currency;
        }
        $currencies    = $newCurrencies;

        // sort temp array by amount.
        $amounts       = array_column($tempData, 'diff_float');
        array_multisort($amounts, SORT_DESC, $tempData);

        // loop all found currencies and build the data array for the chart.
        /**
         * @var int                 $currencyId
         * @var TransactionCurrency $currency
         */
        foreach ($currencies as $currencyId => $currency) {
            $dataSet
                                    = [
                                        'label'           => (string)trans('firefly.spent'),
                                        'type'            => 'bar',
                                        'currency_symbol' => $currency->symbol,
                                        'currency_code'   => $currency->code,
                                        'entries'         => $this->expandNames($tempData),
                                    ];
            $chartData[$currencyId] = $dataSet;
        }

        // loop temp data and place data in correct array:
        foreach ($tempData as $entry) {
            $currencyId                               = $entry['currency_id'];
            $name                                     = $entry['name'];
            $chartData[$currencyId]['entries'][$name] = (float)$entry['difference'];
        }

        $data          = $this->generator->multiSet($chartData);
        $cache->store($data);

        return response()->json($data);
    }

    /**
     * Expenses per budget for all time, as shown on account overview.
     */
    public function expenseBudgetAll(AccountRepositoryInterface $repository, Account $account): JsonResponse
    {
        $start = $repository->oldestJournalDate($account) ?? today(config('app.timezone'))->startOfMonth();
        $end   = today(config('app.timezone'));

        return $this->expenseBudget($account, $start, $end);
    }

    /**
     * Expenses per budget, as shown on account overview.
     */
    public function expenseBudget(Account $account, Carbon $start, Carbon $end): JsonResponse
    {
        $cache     = new CacheProperties();
        $cache->addProperty($account->id);
        $cache->addProperty($start);
        $cache->addProperty($this->convertToPrimary);
        $cache->addProperty($end);
        $cache->addProperty('chart.account.expense-budget');
        if ($cache->has()) {
            return response()->json($cache->get());
        }

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setAccounts(new Collection()->push($account))
            ->setRange($start, $end)
            ->withBudgetInformation()->setTypes([TransactionTypeEnum::WITHDRAWAL->value])
        ;
        $journals  = $collector->getExtractedJournals();
        $chartData = [];
        $result    = [];
        $budgetIds = [];

        /** @var array $journal */
        foreach ($journals as $journal) {
            $budgetId              = (int)$journal['budget_id'];
            $key                   = sprintf('%d-%d', $budgetId, $journal['currency_id']);
            $budgetIds[]           = $budgetId;

            // currency info:
            $currencyId            = (int)$journal['currency_id'];
            $currencyName          = $journal['currency_name'];
            $currencySymbol        = $journal['currency_symbol'];
            $currencyCode          = $journal['currency_code'];
            $currencyDecimalPlaces = $journal['currency_decimal_places'];
            $field                 = 'amount';
            if ($this->convertToPrimary && $this->primaryCurrency->id !== $currencyId) {
                $field                 = 'pc_amount';
                $currencyName          = $this->primaryCurrency->name;
                $currencySymbol        = $this->primaryCurrency->symbol;
                $currencyCode          = $this->primaryCurrency->code;
                $currencyDecimalPlaces = $this->primaryCurrency->decimal_places;

            }

            if (!array_key_exists($key, $result)) {
                $result[$key] = [
                    'total'                   => '0',
                    'budget_id'               => $budgetId,
                    'currency_name'           => $currencyName,
                    'currency_symbol'         => $currencySymbol,
                    'currency_code'           => $currencyCode,
                    'currency_decimal_places' => $currencyDecimalPlaces,
                ];
            }
            $result[$key]['total'] = bcadd((string)$journal[$field], $result[$key]['total']);
        }

        $names     = $this->getBudgetNames($budgetIds);

        foreach ($result as $row) {
            $budgetId          = $row['budget_id'];
            $name              = $names[$budgetId];
            $label             = (string)trans('firefly.name_in_currency', ['name' => $name, 'currency' => $row['currency_name']]);
            $chartData[$label] = ['amount' => $row['total'], 'currency_symbol' => $row['currency_symbol'], 'currency_code' => $row['currency_code']];
        }

        $data      = $this->generator->multiCurrencyPieChart($chartData);
        $cache->store($data);

        return response()->json($data);
    }

    /**
     * Expenses grouped by category for account.
     */
    public function expenseCategoryAll(AccountRepositoryInterface $repository, Account $account): JsonResponse
    {
        $start = $repository->oldestJournalDate($account) ?? today(config('app.timezone'))->startOfMonth();
        $end   = today(config('app.timezone'));

        return $this->expenseCategory($account, $start, $end);
    }

    /**
     * Expenses per category for one single account.
     */
    public function expenseCategory(Account $account, Carbon $start, Carbon $end): JsonResponse
    {
        $cache     = new CacheProperties();
        $cache->addProperty($account->id);
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty($this->convertToPrimary);
        $cache->addProperty('chart.account.expense-category');
        if ($cache->has()) {
            return response()->json($cache->get());
        }

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setAccounts(new Collection()->push($account))->setRange($start, $end)->withCategoryInformation()->setTypes([TransactionTypeEnum::WITHDRAWAL->value]);
        $journals  = $collector->getExtractedJournals();
        $result    = [];
        $chartData = [];

        /** @var array $journal */
        foreach ($journals as $journal) {
            $key                   = sprintf('%d-%d', $journal['category_id'], $journal['currency_id']);
            $field                 = 'amount';
            if (!array_key_exists($key, $result)) {

                // currency info:
                $currencyId            = (int)$journal['currency_id'];
                $currencyName          = $journal['currency_name'];
                $currencySymbol        = $journal['currency_symbol'];
                $currencyCode          = $journal['currency_code'];
                $currencyDecimalPlaces = $journal['currency_decimal_places'];
                if ($this->convertToPrimary && $this->primaryCurrency->id !== $currencyId) {
                    $field                 = 'pc_amount';
                    $currencyName          = $this->primaryCurrency->name;
                    $currencySymbol        = $this->primaryCurrency->symbol;
                    $currencyCode          = $this->primaryCurrency->code;
                    $currencyDecimalPlaces = $this->primaryCurrency->decimal_places;
                }

                $result[$key]          = [
                    'total'                   => '0',
                    'category_id'             => (int)$journal['category_id'],
                    'currency_name'           => $currencyName,
                    'currency_code'           => $currencyCode,
                    'currency_symbol'         => $currencySymbol,
                    'currency_decimal_places' => $currencyDecimalPlaces,
                ];
            }
            $result[$key]['total'] = bcadd((string)$journal[$field], $result[$key]['total']);
        }
        $names     = $this->getCategoryNames(array_keys($result));

        foreach ($result as $row) {
            $categoryId        = $row['category_id'];
            $name              = $names[$categoryId] ?? '(unknown)';
            $label             = (string)trans('firefly.name_in_currency', ['name' => $name, 'currency' => $row['currency_name']]);
            $chartData[$label] = ['amount' => $row['total'], 'currency_symbol' => $row['currency_symbol'], 'currency_code' => $row['currency_code']];
        }

        $data      = $this->generator->multiCurrencyPieChart($chartData);
        $cache->store($data);

        return response()->json($data);
    }

    /**
     * Shows the balances for all the user's frontpage accounts.
     *
     * @throws FireflyException
     *                                              */
    public function frontpage(AccountRepositoryInterface $repository): JsonResponse
    {
        $start          = clone session('start', today(config('app.timezone'))->startOfMonth());
        $end            = clone session('end', today(config('app.timezone'))->endOfMonth());
        $defaultSet     = $repository->getAccountsByType([AccountTypeEnum::DEFAULT->value, AccountTypeEnum::ASSET->value])->pluck('id')->toArray();
        // Log::debug('Default set is ', $defaultSet);
        $frontpage      = Preferences::get('frontpageAccounts', $defaultSet);
        $frontpageArray = !is_array($frontpage->data) ? [] : $frontpage->data;
        Log::debug('Frontpage preference set is ', $frontpageArray);
        if (0 === count($frontpageArray)) {
            Preferences::set('frontpageAccounts', $defaultSet);
            Log::debug('frontpage set is empty!');
        }
        $accounts       = $repository->getAccountsById($frontpageArray);

        // move to end of day for $end.
        $end->endOfDay();

        return response()->json($this->accountBalanceChart($accounts, $start, $end));
    }

    /**
     * Shows the income grouped by category for an account, in all time.
     */
    public function incomeCategoryAll(AccountRepositoryInterface $repository, Account $account): JsonResponse
    {
        $start = $repository->oldestJournalDate($account) ?? today(config('app.timezone'))->startOfMonth();
        $end   = today(config('app.timezone'));

        return $this->incomeCategory($account, $start, $end);
    }

    /**
     * Shows all income per account for each category.
     */
    public function incomeCategory(Account $account, Carbon $start, Carbon $end): JsonResponse
    {
        $cache     = new CacheProperties();
        $cache->addProperty($account->id);
        $cache->addProperty($start);
        $cache->addProperty($this->convertToPrimary);
        $cache->addProperty($end);
        $cache->addProperty('chart.account.income-category');
        if ($cache->has()) {
            return response()->json($cache->get());
        }

        // grab all journals:
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);

        $collector->setAccounts(new Collection()->push($account))->setRange($start, $end)->withCategoryInformation()->setTypes([TransactionTypeEnum::DEPOSIT->value]);
        $journals  = $collector->getExtractedJournals();
        $result    = [];
        $chartData = [];

        /** @var array $journal */
        foreach ($journals as $journal) {
            $key                   = sprintf('%d-%d', $journal['category_id'], $journal['currency_id']);
            $field                 = 'amount';
            if (!array_key_exists($key, $result)) {

                // currency info:
                $currencyId            = (int)$journal['currency_id'];
                $currencyName          = $journal['currency_name'];
                $currencySymbol        = $journal['currency_symbol'];
                $currencyCode          = $journal['currency_code'];
                $currencyDecimalPlaces = $journal['currency_decimal_places'];
                if ($this->convertToPrimary && $this->primaryCurrency->id !== $currencyId) {
                    $field                 = 'pc_amount';
                    $currencyName          = $this->primaryCurrency->name;
                    $currencySymbol        = $this->primaryCurrency->symbol;
                    $currencyCode          = $this->primaryCurrency->code;
                    $currencyDecimalPlaces = $this->primaryCurrency->decimal_places;
                }

                $result[$key]          = [
                    'total'                   => '0',
                    'category_id'             => $journal['category_id'],
                    'currency_name'           => $currencyName,
                    'currency_code'           => $currencyCode,
                    'currency_symbol'         => $currencySymbol,
                    'currency_decimal_places' => $currencyDecimalPlaces,
                ];
            }
            $result[$key]['total'] = bcadd((string)$journal[$field], $result[$key]['total']);
        }

        $names     = $this->getCategoryNames(array_keys($result));
        foreach ($result as $row) {
            $categoryId        = $row['category_id'];
            $name              = $names[$categoryId] ?? '(unknown)';
            $label             = (string)trans('firefly.name_in_currency', ['name' => $name, 'currency' => $row['currency_name']]);
            $chartData[$label] = ['amount' => $row['total'], 'currency_symbol' => $row['currency_symbol'], 'currency_code' => $row['currency_code']];
        }
        $data      = $this->generator->multiCurrencyPieChart($chartData);
        $cache->store($data);

        return response()->json($data);
    }

    /**
     * Shows overview of account during a single period.
     *
     * @throws FireflyException
     */
    public function period(Account $account, Carbon $start, Carbon $end): JsonResponse
    {
        $start->startOfDay();
        $end->endOfDay();
        // TODO not sure if these date ranges will work as expected.
        Log::debug(sprintf('Now in period("%s", "%s")', $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')));
        $cache           = new CacheProperties();
        $cache->addProperty('chart.account.period');
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty($this->convertToPrimary);
        $cache->addProperty($account->id);
        if ($cache->has()) {
            return response()->json($cache->get());
        }

        // collect and filter balances for the entire period.
        $step            = $this->calculateStep($start, $end);
        Log::debug(sprintf('Step is %s', $step));
        $locale          = Steam::getLocale();
        $return          = [];
        $converter       = new ExchangeRateConverter();

        // fix for issue https://github.com/firefly-iii/firefly-iii/issues/8041
        // have to make sure this chart is always based on the balance at the END of the period.
        // This period depends on the size of the chart
        $current         = clone $start;
        $current         = app('navigation')->endOfX($current, $step, null);
        $format          = (string)trans('config.month_and_day_js', [], $locale);
        $accountCurrency = $this->accountRepository->getAccountCurrency($account);
        Log::debug('Get and filter balance for entire range start');
        $range           = Steam::finalAccountBalanceInRange($account, $start, $end, $this->convertToPrimary);
        $range           = Steam::filterAccountBalances($range, $account, $this->convertToPrimary, $accountCurrency);
        Log::debug('Get and filter balance for entire range end');
        // temp, get end balance.
        Log::debug(sprintf('period: Call finalAccountBalance with date/time "%s"', $end->toIso8601String()));
        Steam::finalAccountBalance($account, $end);
        Log::debug('END temp get end balance done');

        $previous        = array_values($range)[0];
        $accountCurrency ??= $this->primaryCurrency; // do this AFTER getting the balances.
        Log::debug('Start chart loop.');

        $newRange        = [];
        $expectedIndex   = 0;
        Log::debug('Balances exist at:');
        foreach ($range as $key => $value) {
            $newRange[] = ['date' => $key, 'info' => $value];
            Log::debug(sprintf('%d - %s (%s)', count($newRange) - 1, $key, json_encode($value)));
        }
        $carbon          = Carbon::createFromFormat('Y-m-d', $newRange[0]['date'])->endOfDay();
        Log::debug(sprintf('Start of loop, $carbon is %s', $carbon->format('Y-m-d H:i:s')));
        while ($end->gte($current)) {
            $momentBalance = $previous;
            // $theDate       = $current->format('Y-m-d');
            Log::debug(sprintf('Now at %s, with momentBalance %s', $current->format('Y-m-d H:i:s'), json_encode($momentBalance)));

            // loop over the array with balances, find one that is earlier or on the same day.
            while ($carbon->lte($current) && array_key_exists($expectedIndex, $newRange)) {
                Log::debug(sprintf('[a] Expected index is %d, $carbon is %s, current is %s', $expectedIndex, $carbon->format('Y-m-d H:i:s'), $current->format('Y-m-d H:i:s')));

                // grab the balance from that particular $expectedIndex
                $momentBalance = $newRange[$expectedIndex]['info'];
                ++$expectedIndex;

                // make new carbon based on the next found date. this should stop the loop.
                if (array_key_exists($expectedIndex, $newRange)) {
                    $carbon = Carbon::createFromFormat('Y-m-d', $newRange[$expectedIndex]['date'])->endOfDay();
                }
            }
            Log::debug(sprintf('momentBalance[%s] is now %s', $current->format('Y-m-d H:i:s'), json_encode($momentBalance)));

            // check, perhaps recalculate the amount in currency X if the
            if ($accountCurrency->id !== $this->primaryCurrency->id && $this->convertToPrimary && array_key_exists($accountCurrency->code, $momentBalance)) {
                $converted                   = $converter->convert($accountCurrency, $this->primaryCurrency, $current, $momentBalance[$accountCurrency->code]);
                $momentBalance['pc_balance'] = $converted;
            }


            $return        = $this->updateChartKeys($return, $momentBalance);
            $previous      = $momentBalance;

            // process each balance thing.
            foreach ($momentBalance as $key => $amount) {
                $label                           = $current->isoFormat($format);
                $return[$key]['entries'][$label] = $amount;
            }
            $current       = app('navigation')->addPeriod($current, $step, 0);
            // here too, to fix #8041, the data is corrected to the end of the period.
            $current       = app('navigation')->endOfX($current, $step, null);
        }
        Log::debug('End of chart loop.');
        // second loop (yes) to create nice array with info! Yay!
        $chartData       = [];

        foreach ($return as $key => $info) {
            if ('balance' !== $key && 'pc_balance' !== $key) {
                // assume it's a currency:
                $setCurrency             = $this->currencyRepository->findByCode((string)$key);
                $info['currency_symbol'] = $setCurrency->symbol;
                $info['currency_code']   = $setCurrency->code;
                $info['label']           = sprintf('%s (%s)', $account->name, $setCurrency->symbol);
            }
            if ('balance' === $key) {
                $info['currency_symbol'] = $accountCurrency->symbol;
                $info['currency_code']   = $accountCurrency->code;
                $info['label']           = sprintf('%s (%s)', $account->name, $accountCurrency->symbol);
            }
            if ('pc_balance' === $key) {
                $info['currency_symbol'] = $this->primaryCurrency->symbol;
                $info['currency_code']   = $this->primaryCurrency->code;
                $info['label']           = sprintf('%s (%s) (%s)', $account->name, (string)trans('firefly.sum'), $this->primaryCurrency->symbol);
            }
            $chartData[] = $info;
        }

        $data            = $this->generator->multiSet($chartData);
        $cache->store($data);

        return response()->json($data);
    }

    private function updateChartKeys(array $array, array $balances): array
    {
        foreach (array_keys($balances) as $key) {
            $array[$key] ??= [
                'key' => $key,
            ];
        }

        return $array;
    }

    /**
     * Shows the balances for a given set of dates and accounts.
     *
     * TODO this chart is not multi currency aware.
     *
     * @throws FireflyException
     */
    public function report(Collection $accounts, Carbon $start, Carbon $end): JsonResponse
    {
        return response()->json($this->accountBalanceChart($accounts, $start, $end));
    }

    /**
     * Shows the balances for all the user's revenue accounts.
     *
     * This chart is multi-currency aware.
     */
    public function revenueAccounts(): JsonResponse
    {
        /** @var Carbon $start */
        $start         = clone session('start', today(config('app.timezone'))->startOfMonth());

        /** @var Carbon $end */
        $end           = clone session('end', today(config('app.timezone'))->endOfMonth());

        $start->startOfDay();
        $end->endOfDay();

        $cache         = new CacheProperties();
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty($this->convertToPrimary);
        $cache->addProperty('chart.account.revenue-accounts');
        if ($cache->has()) {
            return response()->json($cache->get());
        }

        // prep some vars:
        $currencies    = [];
        $chartData     = [];
        $tempData      = [];

        // grab all accounts and names
        $accounts      = $this->accountRepository->getAccountsByType([AccountTypeEnum::REVENUE->value]);
        $accountNames  = $this->extractNames($accounts);

        // grab all balances
        Log::debug(sprintf('revAccounts: accountsBalancesOptimized("%s")', $start->format('Y-m-d H:i:s')));
        Log::debug(sprintf('revAccounts: accountsBalancesOptimized("%s")', $end->format('Y-m-d H:i:s')));
        $startBalances = Steam::accountsBalancesOptimized($accounts, $start, $this->primaryCurrency, $this->convertToPrimary);
        $endBalances   = Steam::accountsBalancesOptimized($accounts, $end, $this->primaryCurrency, $this->convertToPrimary);


        // loop the accounts, then check for balance and currency info.
        foreach ($accounts as $account) {
            // Log::debug(sprintf('[b] Now in account #%d ("%s")', $account->id, $account->name));
            $expenses = $endBalances[$account->id] ?? false;
            if (false === $expenses) {
                Log::error(sprintf('Found no end balance for account #%d', $account->id));

                continue;
            }

            /**
             * @var string $key
             * @var string $endBalance
             */
            foreach ($expenses as $key => $endBalance) {
                if (!$this->convertToPrimary && 'pc_balance' === $key) {
                    //                    Log::debug(sprintf('[a] Will skip expense array "%s"', $key));

                    continue;
                }
                if ($this->convertToPrimary && 'pc_balance' !== $key) {
                    //                    Log::debug(sprintf('[b] Will skip expense array "%s"', $key));

                    continue;
                }
                // Log::debug(sprintf('Will process expense array "%s" with amount %s', $key, $endBalance));
                $searchCode   = $this->convertToPrimary ? $this->primaryCurrency->code : $key;
                $searchCode   = 'balance' === $searchCode || 'pc_balance' === $searchCode ? $this->primaryCurrency->code : $searchCode;
                // Log::debug(sprintf('Search code is %s', $searchCode));
                // see if there is an accompanying start amount.
                // grab the difference and find the currency.
                $startBalance = ($startBalances[$account->id][$key] ?? '0');
                // Log::debug(sprintf('Start balance is %s', $startBalance));
                $diff         = bcsub($endBalance, $startBalance);
                $currencies[$searchCode] ??= $this->currencyRepository->findByCode($searchCode);
                if (0 !== bccomp($diff, '0')) {
                    // store the values in a temporary array.
                    $tempData[] = [
                        'name'        => $accountNames[$account->id],
                        'difference'  => $diff,
                        'diff_float'  => (float)$diff, // intentional float
                        'currency_id' => $currencies[$searchCode]->id,
                    ];
                }
            }
        }


        // recreate currencies, but on ID instead of code.
        $newCurrencies = [];
        foreach ($currencies as $currency) {
            $newCurrencies[$currency->id] = $currency;
        }
        $currencies    = $newCurrencies;

        // sort temp array by amount.
        $amounts       = array_column($tempData, 'diff_float');
        array_multisort($amounts, SORT_ASC, $tempData);

        // loop all found currencies and build the data array for the chart.
        /**
         * @var int                 $currencyId
         * @var TransactionCurrency $currency
         */
        foreach ($currencies as $currencyId => $currency) {
            $dataSet
                                    = [
                                        'label'           => (string)trans('firefly.earned'),
                                        'type'            => 'bar',
                                        'currency_symbol' => $currency->symbol,
                                        'currency_code'   => $currency->code,
                                        'entries'         => $this->expandNames($tempData),
                                    ];
            $chartData[$currencyId] = $dataSet;
        }

        // loop temp data and place data in correct array:
        foreach ($tempData as $entry) {
            $currencyId                               = $entry['currency_id'];
            $name                                     = $entry['name'];
            $chartData[$currencyId]['entries'][$name] = bcmul($entry['difference'], '-1');
        }

        $data          = $this->generator->multiSet($chartData);
        $cache->store($data);

        return response()->json($data);
    }
}
