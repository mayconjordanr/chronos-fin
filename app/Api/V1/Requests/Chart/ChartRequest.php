<?php

/*
 * DashboardChartRequest.php
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

namespace FireflyIII\Api\V1\Requests\Chart;

use Illuminate\Validation\Validator;
use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Support\Http\Api\ValidatesUserGroupTrait;
use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/**
 * Class ChartRequest
 */
class ChartRequest extends FormRequest
{
    use ChecksLogin;
    use ConvertsDataTypes;
    use ValidatesUserGroupTrait;

    protected array $acceptedRoles = [UserRoleEnum::READ_ONLY];

    public function getParameters(): array
    {
        return [
            'start'       => $this->convertDateTime('start')?->startOfDay(),
            'end'         => $this->convertDateTime('end')?->endOfDay(),
            'preselected' => $this->convertString('preselected', 'empty'),
            'period'      => $this->convertString('period', '1M'),
            'accounts'    => $this->arrayFromValue($this->get('accounts')),
        ];
    }

    /**
     * The rules that the incoming request must be matched against.
     */
    public function rules(): array
    {
        return [
            'start'       => 'required|date|after:1970-01-02|before:2038-01-17|before_or_equal:end',
            'end'         => 'required|date|after:1970-01-02|before:2038-01-17|after_or_equal:start',
            'preselected' => sprintf('nullable|in:%s', implode(',', config('firefly.preselected_accounts'))),
            'period'      => sprintf('nullable|in:%s', implode(',', config('firefly.valid_view_ranges'))),
            'accounts'    => 'nullable|array',
            'accounts.*'  => 'exists:accounts,id',
        ];

    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(
            static function (Validator $validator): void {
                // validate transaction query data.
                $data = $validator->getData();
                if (!array_key_exists('accounts', $data)) {
                    // $validator->errors()->add('accounts', trans('validation.filled', ['attribute' => 'accounts']));
                    return;
                }
                if (!is_array($data['accounts'])) {
                    $validator->errors()->add('accounts', trans('validation.filled', ['attribute' => 'accounts']));
                }
            }
        );
        if ($validator->fails()) {
            Log::channel('audit')->error(sprintf('Validation errors in %s', self::class), $validator->errors()->toArray());
        }
    }
}
