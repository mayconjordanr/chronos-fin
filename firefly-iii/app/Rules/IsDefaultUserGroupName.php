<?php

/**
 * IsTransferAccount.php
 * Copyright (c) 2020 james@firefly-iii.org
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

namespace FireflyIII\Rules;

use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Closure;

/**
 * Class IsDefaultUserGroupName
 */
class IsDefaultUserGroupName implements ValidationRule
{
    public function __construct(private readonly UserGroup $userGroup) {}

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        app('log')->debug(sprintf('Now in %s(%s)', __METHOD__, $value));

        // are you owner of this group and the name is the same? fail.
        /** @var User $user */
        $user      = auth()->user();

        /** @var UserRepositoryInterface $userRepos */
        $userRepos = app(UserRepositoryInterface::class);

        $roles     = $userRepos->getRolesInGroup($user, $this->userGroup->id);
        if ($this->userGroup->title === $user->email && in_array('owner', $roles, true)) {
            $fail('validation.administration_owner_rename')->translate();
        }
    }
}
