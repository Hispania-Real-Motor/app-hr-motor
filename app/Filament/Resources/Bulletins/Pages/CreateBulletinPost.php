<?php

namespace App\Filament\Resources\Bulletins\Pages;

use App\Filament\Resources\Bulletins\BulletinPostResource;
use App\Models\BulletinPost;
use App\Models\BulletinActivityLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Filament\Resources\Pages\CreateRecord;

class CreateBulletinPost extends CreateRecord
{
    protected static string $resource = BulletinPostResource::class;

    protected array $pendingImagePaths = [];

    protected array $pendingAudienceRoles = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        $isPublished = (bool) ($data['is_published'] ?? false);

        $this->pendingImagePaths = array_values(array_filter(Arr::wrap($data['images'] ?? [])));
        $this->pendingAudienceRoles = array_values(array_filter(Arr::wrap($data['audience_roles'] ?? [])));

        $data['audience_type'] = $data['audience_type'] ?? BulletinPost::AUDIENCE_ALL;

        BulletinPostResource::validateAudienceData($data);

        $data['published_at'] = $isPublished ? now() : null;
        $data['created_by_user_id'] = $actor instanceof User ? $actor->id : null;
        $data['updated_by_user_id'] = $actor instanceof User ? $actor->id : null;

        unset($data['images'], $data['keep_attachment_ids'], $data['audience_roles']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        /** @var BulletinPost $post */
        $post = $this->record;

        BulletinPostResource::syncAudienceRoles($post, $this->pendingAudienceRoles);

        BulletinPostResource::storeAttachments($post, $this->pendingImagePaths);

        BulletinPostResource::recordActivity(
            actor: $actor,
            action: BulletinActivityLog::ACTION_CREATED,
            post: $post,
            changes: [
                'title' => ['from' => null, 'to' => $post->title],
                'body_excerpt' => ['from' => null, 'to' => BulletinPostResource::excerpt($post->body)],
                'is_published' => ['from' => null, 'to' => $post->is_published ? 'true' : 'false'],
                'published_at' => ['from' => null, 'to' => $post->published_at?->format('Y-m-d H:i:s')],
                'audience_type' => ['from' => null, 'to' => $post->audience_type],
                'audience_roles' => ['from' => null, 'to' => $this->pendingAudienceRoles],
                'images' => ['from' => null, 'to' => (string) $post->attachments()->count()],
            ],
        );
        $this->pendingImagePaths = [];

        if ($post->is_published) {
            BulletinPostResource::notifyPublishedPost($post, $actor);
        }
    }
}
