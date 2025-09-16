<?php


/*
 * ValidatesEnvironmentVariables.php
 * Copyright (c) 2025 james@firefly-iii.org
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

namespace FireflyIII\Console\Commands\Integrity;

use FireflyIII\Console\Commands\ShowsFriendlyMessages;
use Illuminate\Console\Command;

class ValidatesEnvironmentVariables extends Command
{
    use ShowsFriendlyMessages;

    protected $description = 'Makes sure you use the correct variables.';
    protected $signature   = 'integrity:validates-environment-variables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $result = $this->validateLanguage();
        if (false === $result) {
            return Command::FAILURE;
        }
        $result = $this->validateGuard();
        if (false === $result) {
            return Command::FAILURE;
        }
        $result = $this->validateStaticToken();
        if (false === $result) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function validateLanguage(): bool
    {
        $language  = config('firefly.default_language');
        $locale    = config('firefly.default_locale');
        $options   = array_keys(config('firefly.languages'));

        if (!in_array($language, $options, true)) {
            $this->friendlyError(sprintf('DEFAULT_LANGUAGE "%s" is not a valid language for Firefly III.', $language));
            $this->friendlyError('Please check your .env file and make sure you use a valid setting.');
            $this->friendlyError(sprintf('Valid languages are: %s', implode(', ', $options)));

            return false;
        }
        $options[] = 'equal';
        if (!in_array($locale, $options, true)) {
            $this->friendlyError(sprintf('DEFAULT_LOCALE "%s" is not a valid local for Firefly III.', $locale));
            $this->friendlyError('Please check your .env file and make sure you use a valid setting.');
            $this->friendlyError(sprintf('Valid locales are: %s', implode(', ', $options)));

            return false;
        }

        return true;
    }

    private function validateGuard(): bool
    {
        $guard = config('auth.defaults.guard');
        if ('web' !== $guard && 'remote_user_guard' !== $guard) {
            $this->friendlyError(sprintf('AUTHENTICATION_GUARD "%s" is not a valid guard for Firefly III.', $guard));
            $this->friendlyError('Please check your .env file and make sure you use a valid setting.');
            $this->friendlyError('Valid guards are: web, remote_user_guard');

            return false;
        }

        return true;
    }

    private function validateStaticToken(): bool
    {
        $token = (string)config('firefly.static_cron_token');
        if ('' !== $token && 32 !== strlen($token)) {
            $this->friendlyError('STATIC_CRON_TOKEN must be empty or a 32-character string.');
            $this->friendlyError('Please check your .env file and make sure you use a valid setting.');

            return false;
        }

        return true;
    }
}
