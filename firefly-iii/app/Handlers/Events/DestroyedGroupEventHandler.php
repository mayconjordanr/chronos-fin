<?php

/*
 * DestroyedGroupEventHandler.php
 * Copyright (c) 2021 james@firefly-iii.org
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

namespace FireflyIII\Handlers\Events;

use FireflyIII\Enums\WebhookTrigger;
use FireflyIII\Events\DestroyedTransactionGroup;
use FireflyIII\Events\RequestedSendWebhookMessages;
use FireflyIII\Generator\Webhook\MessageGeneratorInterface;
use FireflyIII\Support\Models\AccountBalanceCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Class DestroyedGroupEventHandler
 */
class DestroyedGroupEventHandler
{
    public function runAllHandlers(DestroyedTransactionGroup $event): void
    {
        $this->triggerWebhooks($event);
        $this->updateRunningBalance($event);
    }

    private function triggerWebhooks(DestroyedTransactionGroup $destroyedGroupEvent): void
    {
        app('log')->debug('DestroyedTransactionGroup:triggerWebhooks');
        $group  = $destroyedGroupEvent->transactionGroup;
        $user   = $group->user;

        /** @var MessageGeneratorInterface $engine */
        $engine = app(MessageGeneratorInterface::class);
        $engine->setUser($user);
        $engine->setObjects(new Collection()->push($group));
        $engine->setTrigger(WebhookTrigger::DESTROY_TRANSACTION);
        $engine->generateMessages();
        Log::debug(sprintf('send event RequestedSendWebhookMessages from %s', __METHOD__));
        event(new RequestedSendWebhookMessages());
    }

    private function updateRunningBalance(DestroyedTransactionGroup $event): void
    {
        Log::debug(__METHOD__);
        $group = $event->transactionGroup;
        foreach ($group->transactionJournals as $journal) {
            AccountBalanceCalculator::recalculateForJournal($journal);
        }
    }
}
