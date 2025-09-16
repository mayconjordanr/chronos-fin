<?php

/**
 * FormSupport.php
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

namespace FireflyIII\Support\Form;

use Carbon\Carbon;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Support\MessageBag;
use Throwable;

/**
 * Trait FormSupport
 */
trait FormSupport
{
    public function multiSelect(string $name, ?array $list = null, mixed $selected = null, ?array $options = null): string
    {
        $list ??= [];
        $label    = $this->label($name, $options);
        $options  = $this->expandOptionArray($name, $label, $options);
        $classes  = $this->getHolderClasses($name);
        $selected = $this->fillFieldValue($name, $selected);

        unset($options['autocomplete'], $options['placeholder']);

        try {
            $html = view('form.multi-select', compact('classes', 'name', 'label', 'selected', 'options', 'list'))->render();
        } catch (Throwable $e) {
            app('log')->debug(sprintf('Could not render multi-select(): %s', $e->getMessage()));
            $html = 'Could not render multi-select.';
        }

        return $html;
    }

    protected function label(string $name, ?array $options = null): string
    {
        $options ??= [];
        if (array_key_exists('label', $options)) {
            return $options['label'];
        }
        $name = str_replace('[]', '', $name);

        return (string)trans('form.'.$name);
    }

    /**
     * @param mixed $label
     */
    protected function expandOptionArray(string $name, $label, ?array $options = null): array
    {
        $options ??= [];
        $name                    = str_replace('[]', '', $name);
        $options['class']        = 'form-control';
        $options['id']           = 'ffInput_'.$name;
        $options['autocomplete'] = 'off';
        $options['placeholder']  = ucfirst((string)$label);

        return $options;
    }

    protected function getHolderClasses(string $name): string
    {
        // Get errors from session:
        /** @var null|MessageBag $errors */
        $errors = session('errors');

        if (null !== $errors && $errors->has($name)) {
            return 'form-group has-error has-feedback';
        }

        return 'form-group';
    }

    /**
     * @param null|mixed $value
     *
     * @return mixed
     */
    protected function fillFieldValue(string $name, $value = null)
    {
        if (app('session')->has('preFilled')) {
            $preFilled = session('preFilled');
            $value     = array_key_exists($name, $preFilled) && null === $value ? $preFilled[$name] : $value;
        }

        if (null !== request()->old($name)) {
            $value = request()->old($name);
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    /**
     * @param mixed $selected
     */
    public function select(string $name, ?array $list = null, $selected = null, ?array $options = null): string
    {
        $list ??= [];
        $label    = $this->label($name, $options);
        $options  = $this->expandOptionArray($name, $label, $options);
        $classes  = $this->getHolderClasses($name);
        $selected = $this->fillFieldValue($name, $selected);
        unset($options['autocomplete'], $options['placeholder']);

        try {
            $html = view('form.select', compact('classes', 'name', 'label', 'selected', 'options', 'list'))->render();
        } catch (Throwable $e) {
            app('log')->debug(sprintf('Could not render select(): %s', $e->getMessage()));
            $html = 'Could not render select.';
        }

        return $html;
    }

    protected function getAccountRepository(): AccountRepositoryInterface
    {
        return app(AccountRepositoryInterface::class);
    }

    protected function getDate(): Carbon
    {
        return today(config('app.timezone'));
    }
}
