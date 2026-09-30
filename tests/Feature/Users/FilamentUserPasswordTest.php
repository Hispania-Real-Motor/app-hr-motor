<?php

namespace Tests\Feature\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_password_fields_are_available_to_user_managers_and_never_prefill_the_password(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => null]);

        $response = $this->actingAs($admin)
            ->get(EditUser::getUrl(['record' => $user]));

        $response
            ->assertOk()
            ->assertSee('Nueva contraseña')
            ->assertSee('Confirmar contraseña')
            ->assertDontSee('Cambiar contraseña')
            ->assertDontSee($user->getRawOriginal('password'));
    }

    public function test_editing_without_password_keeps_the_current_hash(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => null]);
        $originalHash = $user->getRawOriginal('password');

        Livewire::actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->call('save', false, false)
            ->assertHasNoErrors();

        $this->assertSame($originalHash, $user->fresh()->getRawOriginal('password'));
    }

    public function test_authorized_user_can_change_password_with_the_hashed_cast_and_a_safe_audit_entry(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => null]);
        $oldPassword = 'password';
        $newPassword = 'NuevaPassword123!';

        Livewire::actingAs($admin);

        $component = Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.new_password', $newPassword)
            ->set('data.new_password_confirmation', $newPassword)
            ->call('save', false, false)
            ->assertHasNoErrors();

        $component->assertDontSee($newPassword);

        $user->refresh();

        $this->assertTrue(Hash::check($newPassword, $user->getRawOriginal('password')));
        $this->assertFalse(Hash::check($oldPassword, $user->getRawOriginal('password')));
        $this->assertNotSame($newPassword, $user->getRawOriginal('password'));
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => $newPassword]));

        $log = UserActivityLog::query()
            ->where('target_user_id', $user->id)
            ->where('action', UserActivityLog::ACTION_UPDATED)
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame([
            'from' => 'Oculta',
            'to' => 'Actualizada',
        ], $log->changes['Contraseña']);
        $serializedLog = json_encode($log->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($newPassword, $serializedLog);
        $this->assertStringNotContainsString($oldPassword, $serializedLog);
    }

    public function test_password_confirmation_is_required_and_must_match(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => null]);

        Livewire::actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.new_password', 'NuevaPassword123!')
            ->call('save', false, false)
            ->assertHasFormErrors(['new_password_confirmation']);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->set('data.new_password', 'NuevaPassword123!')
            ->set('data.new_password_confirmation', 'OtraPassword123!')
            ->call('save', false, false)
            ->assertHasFormErrors(['new_password']);

        $this->assertTrue(Hash::check('password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_user_without_users_manage_cannot_change_a_password_directly(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => null]);

        $this->actingAs($manager)
            ->get(EditUser::getUrl(['record' => $user]))
            ->assertForbidden();

        Livewire::actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertForbidden();

        $this->assertTrue(Hash::check('password', $user->fresh()->getRawOriginal('password')));
        $this->assertDatabaseMissing('user_activity_logs', [
            'target_user_id' => $user->id,
        ]);
    }
}
