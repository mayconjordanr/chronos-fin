<?php

/*
 * InvitationMail.php
 * Copyright (c) 2022 james@firefly-iii.org
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

namespace FireflyIII\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use function Safe\parse_url;

/**
 * Class InvitationMail
 */
class InvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $host;

    /**
     * OAuthTokenCreatedMail constructor.
     */
    public function __construct(public string $invitee, public string $admin, public string $url)
    {
        $host       = parse_url($this->url, PHP_URL_HOST);
        if (is_array($host)) {
            $host = '';
        }
        $this->host = (string) $host;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        return $this
            ->markdown('emails.invitation')
            ->subject((string) trans('email.invite_user_subject'))
        ;
    }
}
