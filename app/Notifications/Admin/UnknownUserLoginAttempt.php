<?php

/*
 * UnknownUserLoginAttempt.php
 * Copyright (c) 2024 james@firefly-iii.org.
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

namespace FireflyIII\Notifications\Admin;

use FireflyIII\Notifications\Notifiables\OwnerNotifiable;
use FireflyIII\Notifications\ReturnsAvailableChannels;
use FireflyIII\Support\Facades\FireflyConfig;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Request;
use NotificationChannels\Pushover\PushoverMessage;

class UnknownUserLoginAttempt extends Notification
{
    use Queueable;

    public function __construct(private string $address) {}

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toArray(OwnerNotifiable $notifiable): array
    {
        return [
        ];
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toMail(OwnerNotifiable $notifiable): MailMessage
    {
        $ip        = Request::ip();
        $host      = Steam::getHostName($ip);
        $userAgent = Request::userAgent();
        $time      = now(config('app.timezone'))->isoFormat((string) trans('config.date_time_js'));

        return new MailMessage()
            ->markdown('emails.owner.unknown-user', ['address' => $this->address, 'ip' => $ip, 'host' => $host, 'userAgent' => $userAgent, 'time' => $time])
            ->subject((string) trans('email.unknown_user_subject'))
        ;
    }

    //    /**
    //     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
    //     */
    //
    //    public function toNtfy(OwnerNotifiable $notifiable): Message
    //    {
    //        $settings = ReturnsSettings::getSettings('ntfy', 'owner', null);
    //        $message  = new Message();
    //        $ip       = Request::ip();
    //        $message->topic($settings['ntfy_topic']);
    //        $message->title((string) trans('email.unknown_user_subject'));
    //        $message->body((string) trans('email.unknown_user_message', ['address' => $this->address, 'ip' => $ip]));
    //
    //        return $message;
    //    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toPushover(OwnerNotifiable $notifiable): PushoverMessage
    {
        $ip = Request::ip();

        return PushoverMessage::create((string) trans('email.unknown_user_message', ['address' => $this->address, 'ip' => $ip]))
            ->title((string) trans('email.unknown_user_subject'))
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toSlack(OwnerNotifiable $notifiable): SlackMessage
    {
        $ip = Request::ip();

        return new SlackMessage()->content(
            (string) trans('email.unknown_user_body', ['address' => $this->address, 'ip' => $ip])
        );
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function via(OwnerNotifiable $notifiable): array
    {
        $channels   = ReturnsAvailableChannels::returnChannels('owner');
        $isDemoSite = FireflyConfig::get('is_demo_site', false)->data;
        if (true === $isDemoSite) {
            return array_diff($channels, ['mail']);
        }

        return $channels;
    }
}
