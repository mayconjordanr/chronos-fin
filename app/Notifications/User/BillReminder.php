<?php

/*
 * BillReminder.php
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

namespace FireflyIII\Notifications\User;

use FireflyIII\Models\Bill;
use FireflyIII\Notifications\ReturnsAvailableChannels;
use FireflyIII\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Pushover\PushoverMessage;

/**
 * Class BillReminder
 */
class BillReminder extends Notification
{
    use Queueable;

    public function __construct(private Bill $bill, private string $field, private int $diff) {}

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toArray(User $notifiable): array
    {
        return [
        ];
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toMail(User $notifiable): MailMessage
    {
        return new MailMessage()
            ->markdown('emails.bill-warning', ['field' => $this->field, 'diff' => $this->diff, 'bill' => $this->bill])
            ->subject($this->getSubject())
        ;
    }

    private function getSubject(): string
    {
        if (0 === $this->diff) {
            return (string) trans(sprintf('email.bill_warning_subject_now_%s', $this->field), ['diff' => $this->diff, 'name' => $this->bill->name]);
        }

        return (string) trans(sprintf('email.bill_warning_subject_%s', $this->field), ['diff' => $this->diff, 'name' => $this->bill->name]);
    }

    //    public function toNtfy(User $notifiable): Message
    //    {
    //        $settings = ReturnsSettings::getSettings('ntfy', 'user', $notifiable);
    //        $message  = new Message();
    //        $message->topic($settings['ntfy_topic']);
    //        $message->title($this->getSubject());
    //        $message->body((string) trans('email.bill_warning_please_action'));
    //
    //        return $message;
    //    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toPushover(User $notifiable): PushoverMessage
    {
        return PushoverMessage::create((string) trans('email.bill_warning_please_action'))
            ->title($this->getSubject())
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function toSlack(User $notifiable): SlackMessage
    {
        $bill = $this->bill;
        $url  = route('bills.show', [$bill->id]);

        return new SlackMessage()
            ->warning()
            ->attachment(static function ($attachment) use ($bill, $url): void {
                $attachment->title((string) trans('firefly.visit_bill', ['name' => $bill->name]), $url);
            })
            ->content($this->getSubject())
        ;
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function via(User $notifiable): array
    {
        return ReturnsAvailableChannels::returnChannels('user', $notifiable);
    }
}
