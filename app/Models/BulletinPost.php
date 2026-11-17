<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BulletinPost extends Model
{
    use HasFactory;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_ROLES = 'roles';

    protected $fillable = [
        'title',
        'body',
        'audience_type',
        'is_published',
        'published_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function setBodyAttribute(mixed $value): void
    {
        $this->attributes['body'] = self::sanitizeBody((string) $value);
    }

    public static function sanitizeBody(string $body): string
    {
        $sanitized = Str::sanitizeHtml($body);

        return (string) preg_replace_callback(
            '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/isu',
            static function (array $matches): string {
                $tag = $matches[0];
                $href = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalizedHref = self::normalizeLinkHref($href);
                $isExternal = (bool) preg_match('/^https?:\/\//i', $normalizedHref);

                $encodedHref = htmlspecialchars($normalizedHref, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tag = (string) preg_replace(
                    '/\bhref\s*=\s*(["\']).*?\1/isu',
                    'href="' . $encodedHref . '"',
                    $tag,
                    1,
                );

                if (! $isExternal) {
                    return $tag;
                }

                $tag = (string) preg_replace('/\s+target\s*=\s*(["\']).*?\1/isu', '', $tag);
                $tag = (string) preg_replace('/\s+rel\s*=\s*(["\']).*?\1/isu', '', $tag);

                return rtrim($tag, '>') . ' target="_blank" rel="noopener noreferrer">';
            },
            $sanitized,
        );
    }

    private static function normalizeLinkHref(string $href): string
    {
        $href = trim($href);

        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        if (preg_match('/^(?:https?|mailto|tel|ftp|ftps|sms|callto|xmpp):/i', $href)) {
            return $href;
        }

        if (preg_match('/^(?:www\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}(?::\d+)?(?:[\/?#].*)?$/i', $href)) {
            return 'https://' . $href;
        }

        return $href;
    }

    protected static function booted(): void
    {
        static::deleting(function (self $bulletin): void {
            $bulletin->attachments()->each(fn (BulletinPostAttachment $attachment) => $attachment->delete());
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(BulletinPostAttachment::class, 'bulletin_post_id')->orderBy('sort_order')->orderBy('id');
    }

    public function audienceRoles(): HasMany
    {
        return $this->hasMany(BulletinPostRole::class);
    }

    public function isVisibleTo(?User $user): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }

        return $this->audience_type === self::AUDIENCE_ALL
            || app_user_has_any_role($user, $this->audienceRoles->pluck('role')->all());
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $roles = app_effective_roles($user);

        return $query->where(function (Builder $audienceQuery) use ($roles): void {
            $audienceQuery
                ->where('audience_type', self::AUDIENCE_ALL)
                ->orWhere(function (Builder $roleQuery) use ($roles): void {
                    $roleQuery
                        ->where('audience_type', self::AUDIENCE_ROLES)
                        ->whereHas('audienceRoles', fn (Builder $query): Builder => $query->whereIn('role', $roles));
                });
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->whereNotNull('published_at');
    }
}
