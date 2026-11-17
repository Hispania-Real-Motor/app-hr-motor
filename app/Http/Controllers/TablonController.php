<?php

namespace App\Http\Controllers;

use App\Models\BulletinPost;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class TablonController extends Controller
{
    public function index(): View
    {
        $mentionableUsers = User::query()
            ->where('is_active', true)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        $posts = BulletinPost::query()
            ->with(['creator:id,name,avatar_path', 'attachments'])
            ->published()
            ->visibleTo(auth()->user())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(10);

        $this->hydrateBulletinPosts($posts->getCollection(), $mentionableUsers);

        return view('tablon.index', compact('posts'));
    }

    private function hydrateBulletinPosts(Collection $posts, Collection $mentionableUsers): void
    {
        $posts->each(function (BulletinPost $post) use ($mentionableUsers): void {
            $post->setAttribute('rendered_body_html', $this->renderBodyHtml((string) $post->body, $mentionableUsers));
        });
    }

    private function renderBodyHtml(string $body, Collection $users): string
    {
        $sanitizedBody = BulletinPost::sanitizeBody($body);
        $hasHtmlMarkup = $sanitizedBody !== strip_tags($sanitizedBody);
        $escapedBody = $hasHtmlMarkup ? $sanitizedBody : nl2br(e($body));

        if ($escapedBody === '' || $users->isEmpty()) {
            return $escapedBody;
        }

        $sortedUsers = $users
            ->sortByDesc(fn (User $user): int => mb_strlen((string) $user->name))
            ->values();

        foreach ($sortedUsers as $user) {
            $name = trim((string) $user->name);

            if ($name === '') {
                continue;
            }

            $escapedMention = e('@' . $name);
            $replacement = '<a href="' . e(route('users.show', $user)) . '" class="font-semibold text-sky-600 transition hover:text-sky-700 hover:underline">@' . e($name) . '</a>';
            $escapedBody = str_replace($escapedMention, $replacement, $escapedBody);
        }

        return $escapedBody;
    }
}
