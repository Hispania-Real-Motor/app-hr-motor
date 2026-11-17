<?php

namespace Tests\Feature;

use App\Filament\Resources\Bulletins\BulletinPostResource;
use App\Models\BulletinActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\BulletinPost;
use App\Models\BulletinPostAttachment;
use App\Models\User;
use App\Notifications\AdminPriorityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BulletinTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('public')->deleteDirectory('bulletin-posts');

        parent::tearDown();
    }

    public function test_public_bulletin_is_visible_to_all_authenticated_users(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
        ]);
        $creator = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        BulletinPost::query()->create([
            'title' => 'Aviso visible',
            'body' => 'Este contenido debe verse para todos.',
            'is_published' => true,
            'published_at' => now(),
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        BulletinPost::query()->create([
            'title' => 'Borrador oculto',
            'body' => html_entity_decode('No deber&iacute;a aparecer en el tabl&oacute;n p&uacute;blico.'),
            'is_published' => false,
            'published_at' => null,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        $response = $this->actingAs($user)->get(route('tablon.index'));

        $response
            ->assertOk()
            ->assertSee('Tabl&oacute;n de anuncios', false)
            ->assertSee('Aviso visible')
            ->assertSee(route('users.show', $creator))
            ->assertSee($creator->avatar_url)
            ->assertDontSee('Borrador oculto');
    }

    public function test_role_targeted_bulletin_is_only_visible_to_matching_roles(): void
    {
        $commercial = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => User::ROLE_COMMERCIAL]);
        $marketing = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => User::ROLE_MARKETING]);
        $post = BulletinPost::query()->create([
            'title' => 'Aviso comercial',
            'body' => 'Solo para comercial.',
            'audience_type' => BulletinPost::AUDIENCE_ROLES,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $post->audienceRoles()->create(['role' => User::ROLE_COMMERCIAL]);

        $this->actingAs($commercial)->get(route('tablon.index'))->assertSee('Aviso comercial');
        $this->actingAs($marketing)->get(route('tablon.index'))->assertDontSee('Aviso comercial');
    }

    public function test_role_targeted_bulletin_notifies_each_matching_user_once(): void
    {
        Notification::fake();
        $recipient = User::factory()->create(['role' => User::ROLE_COMMERCIAL, 'extra_role' => User::ROLE_MARKETING]);
        $other = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => User::ROLE_HUMAN_RESOURCES]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $post = BulletinPost::query()->create([
            'title' => 'Aviso segmentado',
            'body' => 'Contenido.',
            'audience_type' => BulletinPost::AUDIENCE_ROLES,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $post->audienceRoles()->createMany([
            ['role' => User::ROLE_COMMERCIAL],
            ['role' => User::ROLE_MARKETING],
        ]);

        BulletinPostResource::notifyPublishedPost($post, $actor);

        Notification::assertSentTo($recipient, AdminPriorityNotification::class);
        Notification::assertNotSentTo($other, AdminPriorityNotification::class);
        Notification::assertCount(1);
    }

    public function test_legacy_bulletin_without_audience_is_visible_and_notifies_all_active_users(): void
    {
        Notification::fake();
        $recipient = User::factory()->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $post = BulletinPost::query()->create([
            'title' => 'Aviso global',
            'body' => 'Contenido.',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->actingAs($recipient)->get(route('tablon.index'))->assertSee('Aviso global');
        BulletinPostResource::notifyPublishedPost($post, $actor);

        Notification::assertSentTo($recipient, AdminPriorityNotification::class);
        Notification::assertNotSentTo($inactive, AdminPriorityNotification::class);
    }

    public function test_rich_bulletin_content_is_sanitized_and_keeps_basic_formatting(): void
    {
        $user = User::factory()->create();
        BulletinPost::query()->create([
            'title' => 'Aviso con formato',
            'body' => '<h2>Encabezado</h2><p><strong>Importante</strong> <em>hoy</em> <u>solamente</u></p><ul><li>Viñeta</li></ul><ol><li>Paso</li></ol><p><a href="google.com">google.com</a></p><p><span class="color" data-color="#dc2626">Color</span></p><script>alert(1)</script>',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('tablon.index'));

        $response
            ->assertOk()
            ->assertSee('<h2>Encabezado</h2>', false)
            ->assertSee('<strong>Importante</strong>', false)
            ->assertSee('<em>hoy</em>', false)
            ->assertSee('<u>solamente</u>', false)
            ->assertSee('<ul><li>Viñeta</li></ul>', false)
            ->assertSee('<ol><li>Paso</li></ol>', false)
            ->assertSee('href="https://google.com"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('>google.com</a>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}

