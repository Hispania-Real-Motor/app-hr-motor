<?php

namespace App\Filament\Resources\Bulletins\Pages;

use App\Filament\Resources\Bulletins\BulletinPostResource;
use App\Models\BulletinPost;
use App\Models\BulletinActivityLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBulletinPost extends EditRecord
{
    protected static string $resource = BulletinPostResource::class;

    protected array $pendingActivityLogChanges = [];

    protected array $pendingImagePaths = [];

    protected array $pendingKeepAttachmentIds = [];

    protected array $pendingAudienceRoles = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('attachments');

        $data['keep_attachment_ids'] = $this->record->attachments->pluck('id')->all();
        $data['audience_roles'] = $this->record->audienceRoles()->pluck('role')->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Borrar')
                ->using(function (BulletinPost $record): bool {
                    return BulletinPostResource::deleteBulletin($record);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $post = $this->getRecord();
        $actor = auth()->user();
        $isPublished = (bool) ($data['is_published'] ?? false);
        $currentAttachmentCount = $post->attachments()->count();

        $this->pendingImagePaths = array_values(array_filter(Arr::wrap($data['images'] ?? [])));
        $this->pendingAudienceRoles = array_values(array_filter(Arr::wrap($data['audience_roles'] ?? [])));
        BulletinPostResource::validateAudienceData($data);
        $this->pendingKeepAttachmentIds = collect($data['keep_attachment_ids'] ?? [])
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        $data['updated_by_user_id'] = $actor instanceof User ? $actor->id : null;

        if ($isPublished && ! $post->is_published) {
            $data['published_at'] = now();
        }

        $audienceChangeData = $data;
        $audienceChangeData['audience_roles'] = $this->pendingAudienceRoles;

        unset($data['images'], $data['keep_attachment_ids'], $data['audience_roles']);

        $data['audience_type'] = $data['audience_type'] ?? BulletinPost::AUDIENCE_ALL;

        $this->pendingActivityLogChanges = BulletinPostResource::buildChangeSet($post, $audienceChangeData);

        $finalAttachmentCount = $this->currentAttachmentCount($post);

        if ($currentAttachmentCount !== $finalAttachmentCount) {
            $this->pendingActivityLogChanges['images'] = [
                'from' => (string) $currentAttachmentCount,
                'to' => $finalAttachmentCount > 0 ? (string) $finalAttachmentCount : null,
            ];
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->pendingActivityLogChanges === []) {
            return;
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        /** @var BulletinPost $post */
        $post = $this->record;

        BulletinPostResource::syncAudienceRoles($post, $this->pendingAudienceRoles);

        $existingAttachments = $post->attachments()->get();
        $attachmentsToDelete = $existingAttachments->reject(
            fn ($attachment): bool => in_array($attachment->id, $this->pendingKeepAttachmentIds, true),
        );

        $attachmentsToDelete->each(static function ($attachment): void {
            $attachment->delete();
        });

        $keptAttachments = $existingAttachments->filter(
            fn ($attachment): bool => in_array($attachment->id, $this->pendingKeepAttachmentIds, true),
        );
        $nextSortOrder = ((int) ($keptAttachments->max('sort_order') ?? -1)) + 1;

        BulletinPostResource::storeAttachments($post, $this->pendingImagePaths, $nextSortOrder);

        BulletinPostResource::recordActivity(
            actor: $actor,
            action: BulletinActivityLog::ACTION_UPDATED,
            post: $post,
            changes: $this->pendingActivityLogChanges,
        );

        if ($post->is_published && ($post->wasChanged('is_published') || $post->wasChanged('published_at'))) {
            BulletinPostResource::notifyPublishedPost($post, $actor);
        }

        $this->pendingActivityLogChanges = [];
        $this->pendingImagePaths = [];
        $this->pendingKeepAttachmentIds = [];
    }

    protected function currentAttachmentCount(BulletinPost $post): int
    {
        $keptAttachmentIds = $this->pendingKeepAttachmentIds;

        return $post->attachments()
            ->whereIn('id', $keptAttachmentIds)
            ->count()
            + count($this->pendingImagePaths);
    }
}
