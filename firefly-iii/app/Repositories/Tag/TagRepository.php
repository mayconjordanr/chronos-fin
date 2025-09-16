<?php

/**
 * TagRepository.php
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

namespace FireflyIII\Repositories\Tag;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Factory\TagFactory;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Attachment;
use FireflyIII\Models\Location;
use FireflyIII\Models\Note;
use FireflyIII\Models\Tag;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Repositories\UserGroup\UserGroupInterface;
use FireflyIII\Support\Repositories\UserGroup\UserGroupTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

/**
 * Class TagRepository.
 */
class TagRepository implements TagRepositoryInterface, UserGroupInterface
{
    use UserGroupTrait;

    public function count(): int
    {
        return $this->user->tags()->count();
    }

    /**
     * @throws Exception
     */
    public function destroy(Tag $tag): bool
    {
        DB::table('tag_transaction_journal')->where('tag_id', $tag->id)->delete();
        $tag->transactionJournals()->sync([]);
        $tag->delete();

        return true;
    }

    /**
     * Destroy all tags.
     */
    public function destroyAll(): void
    {
        Log::channel('audit')->info('Delete all tags through destroyAll');
        $tags = $this->get();

        /** @var Tag $tag */
        foreach ($tags as $tag) {
            DB::table('tag_transaction_journal')->where('tag_id', $tag->id)->delete();
            $tag->delete();
        }
    }

    public function get(): Collection
    {
        return $this->user->tags()->orderBy('tag', 'ASC')->get(['tags.*']);
    }

    public function expenseInPeriod(Tag $tag, Carbon $start, Carbon $end): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);

        $collector->setUser($this->user);
        $collector->setRange($start, $end)->setTypes([TransactionTypeEnum::WITHDRAWAL->value])->setTag($tag);

        return $collector->getExtractedJournals();
    }

    public function find(int $tagId): ?Tag
    {
        /** @var null|Tag */
        return $this->user->tags()->find($tagId);
    }

    public function findByTag(string $tag): ?Tag
    {
        /** @var null|Tag */
        return $this->user->tags()->where('tag', $tag)->first();
    }

    public function firstUseDate(Tag $tag): ?Carbon
    {
        /** @var null|Carbon */
        return $tag->transactionJournals()->orderBy('date', 'ASC')->first()?->date;
    }

    public function getAttachments(Tag $tag): Collection
    {
        $set  = $tag->attachments()->get();
        $disk = Storage::disk('upload');

        return $set->each(
            static function (Attachment $attachment) use ($disk): void { // @phpstan-ignore-line
                /** @var null|Note $note */
                $note                    = $attachment->notes()->first();
                // only used in v1 view of tags
                $attachment->file_exists = $disk->exists($attachment->fileName());
                $attachment->notes_text  = null === $note ? '' : $note->text;
            }
        );
    }

    public function getTagsInYear(?int $year): array
    {
        // get all tags in the year (if present):
        $tagQuery   = $this->user->tags()->with(['locations', 'attachments'])->orderBy('tags.tag');

        // add date range (or not):
        if (null === $year) {
            app('log')->debug('Get tags without a date.');
            $tagQuery->whereNull('tags.date');
        }

        if (null !== $year) {
            $year = min(2038, max(1970, $year));
            app('log')->debug(sprintf('Get tags with year %s.', $year));
            $tagQuery->where('tags.date', '>=', sprintf('%d-01-01 00:00:00', $year))->where('tags.date', '<=', sprintf('%d-12-31 23:59:59', $year));
        }
        $collection = $tagQuery->get();
        $return     = [];

        /** @var Tag $tag */
        foreach ($collection as $tag) {
            // return value for tag cloud:
            $return[$tag->id] = [
                'tag'         => $tag->tag,
                'id'          => $tag->id,
                'created_at'  => $tag->created_at,
                'location'    => $tag->locations->first(),
                'attachments' => $tag->attachments,
            ];
        }

        return $return;
    }

    public function incomeInPeriod(Tag $tag, Carbon $start, Carbon $end): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);

        $collector->setUser($this->user);
        $collector->setRange($start, $end)->setTypes([TransactionTypeEnum::DEPOSIT->value])->setTag($tag);

        return $collector->getExtractedJournals();
    }

    public function lastUseDate(Tag $tag): ?Carbon
    {
        /** @var null|Carbon */
        return $tag->transactionJournals()->orderBy('date', 'DESC')->first()?->date;
    }

    /**
     * Will return the newest tag (if known) or NULL.
     */
    public function newestTag(): ?Tag
    {
        /** @var null|Tag */
        return $this->user->tags()->whereNotNull('date')->orderBy('date', 'DESC')->first();
    }

    public function oldestTag(): ?Tag
    {
        /** @var null|Tag */
        return $this->user->tags()->whereNotNull('date')->orderBy('date', 'ASC')->first();
    }

    /**
     * Find one or more tags based on the query.
     */
    public function searchTag(string $query): Collection
    {
        $search = sprintf('%%%s%%', $query);

        return $this->user->tags()->whereLike('tag', $search)->get(['tags.*']);
    }

    /**
     * Search the users tags.
     */
    public function searchTags(string $query, int $limit): Collection
    {
        $tags = $this->user->tags()->orderBy('tag', 'ASC');
        if ('' !== $query) {
            $search = sprintf('%%%s%%', $query);
            $tags->whereLike('tag', $search);
        }

        return $tags->take($limit)->get(['tags.*']);
    }

    public function store(array $data): Tag
    {
        /** @var TagFactory $factory */
        $factory = app(TagFactory::class);
        $factory->setUser($this->user);

        return $factory->create($data);
    }

    public function sumsOfTag(Tag $tag, ?Carbon $start, ?Carbon $end): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);

        if ($start instanceof Carbon && $end instanceof Carbon) {
            $collector->setRange($start, $end);
        }

        $collector->setTag($tag)->withAccountInformation();
        $journals  = $collector->getExtractedJournals();

        $sums      = [];

        /** @var array $journal */
        foreach ($journals as $journal) {
            $found                    = false;

            /** @var array $localTag */
            foreach ($journal['tags'] as $localTag) {
                if ($localTag['id'] === $tag->id) {
                    $found = true;
                }
            }
            if (false === $found) {
                continue;
            }
            $currencyId               = (int) $journal['currency_id'];
            $sums[$currencyId] ??= [
                'currency_id'                               => $currencyId,
                'currency_name'                             => $journal['currency_name'],
                'currency_symbol'                           => $journal['currency_symbol'],
                'currency_decimal_places'                   => $journal['currency_decimal_places'],
                TransactionTypeEnum::WITHDRAWAL->value      => '0',
                TransactionTypeEnum::DEPOSIT->value         => '0',
                TransactionTypeEnum::TRANSFER->value        => '0',
                TransactionTypeEnum::RECONCILIATION->value  => '0',
                TransactionTypeEnum::OPENING_BALANCE->value => '0',
            ];

            // add amount to correct type:
            $amount                   = Steam::positive((string) $journal['amount']);
            $type                     = $journal['transaction_type_type'];
            if (TransactionTypeEnum::WITHDRAWAL->value === $type) {
                $amount = bcmul($amount, '-1');
            }
            $sums[$currencyId][$type] = bcadd((string) $sums[$currencyId][$type], $amount);

            $foreignCurrencyId        = $journal['foreign_currency_id'];
            if (null !== $foreignCurrencyId && 0 !== $foreignCurrencyId) {
                $sums[$foreignCurrencyId] ??= [
                    'currency_id'                               => $foreignCurrencyId,
                    'currency_name'                             => $journal['foreign_currency_name'],
                    'currency_symbol'                           => $journal['foreign_currency_symbol'],
                    'currency_decimal_places'                   => $journal['foreign_currency_decimal_places'],
                    TransactionTypeEnum::WITHDRAWAL->value      => '0',
                    TransactionTypeEnum::DEPOSIT->value         => '0',
                    TransactionTypeEnum::TRANSFER->value        => '0',
                    TransactionTypeEnum::RECONCILIATION->value  => '0',
                    TransactionTypeEnum::OPENING_BALANCE->value => '0',
                ];
                // add foreign amount to correct type:
                $amount                          = Steam::positive((string) $journal['foreign_amount']);
                if (TransactionTypeEnum::WITHDRAWAL->value === $type) {
                    $amount = bcmul($amount, '-1');
                }
                $sums[$foreignCurrencyId][$type] = bcadd((string) $sums[$foreignCurrencyId][$type], $amount);
            }
        }

        return $sums;
    }

    public function tagEndsWith(string $query): Collection
    {
        $search = sprintf('%%%s', $query);

        return $this->user->tags()->whereLike('tag', $search)->get(['tags.*']);
    }

    public function tagStartsWith(string $query): Collection
    {
        $search = sprintf('%s%%', $query);

        return $this->user->tags()->whereLike('tag', $search)->get(['tags.*']);
    }

    public function transferredInPeriod(Tag $tag, Carbon $start, Carbon $end): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user);
        $collector->setRange($start, $end)->setTypes([TransactionTypeEnum::TRANSFER->value])->setTag($tag);

        return $collector->getExtractedJournals();
    }

    public function update(Tag $tag, array $data): Tag
    {
        if (array_key_exists('tag', $data)) {
            $tag->tag = $data['tag'];
        }
        if (array_key_exists('date', $data)) {
            $tag->date = $data['date'];
        }
        if (array_key_exists('description', $data)) {
            $tag->description = $data['description'];
        }

        $tag->latitude  = null;
        $tag->longitude = null;
        $tag->zoomLevel = null;
        $tag->save();

        // update, delete or create location:
        $updateLocation = $data['update_location'] ?? false;
        $deleteLocation = $data['remove_location'] ?? false;

        // location must be updated?
        if (true === $updateLocation) {
            // if all set to NULL, delete
            if (null === $data['latitude'] && null === $data['longitude'] && null === $data['zoom_level']) {
                $tag->locations()->delete();
            }

            // otherwise, update or create.
            if (!(null === $data['latitude'] && null === $data['longitude'] && null === $data['zoom_level'])) {
                $location             = $this->getLocation($tag);
                if (!$location instanceof Location) {
                    $location = new Location();
                    $location->locatable()->associate($tag);
                }

                $location->latitude   = $data['latitude'] ?? config('firefly.default_location.latitude');
                $location->longitude  = $data['longitude'] ?? config('firefly.default_location.longitude');
                $location->zoom_level = $data['zoom_level'] ?? config('firefly.default_location.zoom_level');
                $location->save();
            }
        }
        if (true === $deleteLocation) {
            $tag->locations()->delete();
        }

        return $tag;
    }

    public function getLocation(Tag $tag): ?Location
    {
        /** @var null|Location */
        return $tag->locations()->first();
    }
}
