<?php

namespace Tests\Feature\Contacts;

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContactLogs;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\ContentActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentContactManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_manager_can_open_the_filament_contacts_section(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'gestor@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'group_role' => User::ROLE_MANAGER,
            'permission_key' => 'contacts.manage',
            'granted_by_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->get(ContactResource::getUrl())
            ->assertOk()
            ->assertSee('Contactos');
    }

    public function test_admin_can_open_contact_logs_in_filament(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        ContentActivityLog::query()->create([
            'content_type' => ContentActivityLog::CONTENT_TYPE_CONTACT,
            'action' => ContentActivityLog::ACTION_CREATED,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'target_name' => 'Contacto Log',
            'target_reference' => '3001',
            'changes' => [
                'name' => ['from' => null, 'to' => 'Contacto Log'],
            ],
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(ContactResource::getUrl('logs'))
            ->assertOk()
            ->assertSee('Logs de contactos')
            ->assertSee('Contacto Log');
    }

    public function test_admin_can_create_contact_in_filament_and_it_is_logged(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        Livewire::actingAs($admin);

        Livewire::test(CreateContact::class)
            ->set('data.name', 'Soporte Motorflash')
            ->set('data.email', 'soporte@example.com')
            ->set('data.phone', '954000123')
            ->set('data.enreach_extension', '1234')
            ->call('create')
            ->assertHasNoErrors();

        $contact = Contact::query()->where('name', 'Soporte Motorflash')->firstOrFail();

        $log = ContentActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame(ContentActivityLog::CONTENT_TYPE_CONTACT, $log->content_type);
        $this->assertSame(ContentActivityLog::ACTION_CREATED, $log->action);
        $this->assertSame('Soporte Motorflash', $log->target_name);
        $this->assertSame('1234', $log->target_reference);
        $this->assertSame([
            'name' => ['from' => null, 'to' => 'Soporte Motorflash'],
            'email' => ['from' => null, 'to' => 'soporte@example.com'],
            'phone' => ['from' => null, 'to' => '954000123'],
            'enreach_extension' => ['from' => null, 'to' => '1234'],
        ], $log->changes);
        $this->assertSame('954000123', $contact->phone);
        $this->assertSame('1234', $contact->enreach_extension);
    }

    public function test_admin_can_update_contact_in_filament_and_it_is_logged(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        $contact = Contact::query()->create([
            'name' => 'Contacto Inicial',
            'email' => 'inicial@example.com',
            'phone' => '910000111',
            'enreach_extension' => '2001',
        ]);

        Livewire::actingAs($admin);

        Livewire::test(EditContact::class, ['record' => $contact->getKey()])
            ->set('data.name', 'Contacto Actualizado')
            ->set('data.email', 'actualizado@example.com')
            ->set('data.phone', '910000222')
            ->set('data.enreach_extension', '2002')
            ->call('save', false, false)
            ->assertHasNoErrors();

        $contact->refresh();

        $log = ContentActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame(ContentActivityLog::CONTENT_TYPE_CONTACT, $log->content_type);
        $this->assertSame(ContentActivityLog::ACTION_UPDATED, $log->action);
        $this->assertSame('Contacto Actualizado', $log->target_name);
        $this->assertSame('2002', $log->target_reference);
        $this->assertSame([
            'Nombre' => ['from' => 'Contacto Inicial', 'to' => 'Contacto Actualizado'],
            'Correo' => ['from' => 'inicial@example.com', 'to' => 'actualizado@example.com'],
            'Teléfono' => ['from' => '910000111', 'to' => '910000222'],
            'Extensión Enreach' => ['from' => '2001', 'to' => '2002'],
        ], $log->changes);
        $this->assertSame('Contacto Actualizado', $contact->name);
        $this->assertSame('actualizado@example.com', $contact->email);
    }

    public function test_admin_can_delete_contact_in_filament_and_it_is_logged(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        $contact = Contact::query()->create([
            'name' => 'Contacto a borrar',
            'email' => 'borrar@example.com',
            'phone' => '911000333',
            'enreach_extension' => '2003',
        ]);

        Livewire::actingAs($admin);

        Livewire::test(EditContact::class, ['record' => $contact->getKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('contacts', [
            'id' => $contact->id,
        ]);

        $log = ContentActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame(ContentActivityLog::CONTENT_TYPE_CONTACT, $log->content_type);
        $this->assertSame(ContentActivityLog::ACTION_DELETED, $log->action);
        $this->assertSame('Contacto a borrar', $log->target_name);
        $this->assertSame('2003', $log->target_reference);
        $this->assertSame([
            'name' => ['from' => 'Contacto a borrar', 'to' => null],
            'email' => ['from' => 'borrar@example.com', 'to' => null],
            'phone' => ['from' => '911000333', 'to' => null],
            'enreach_extension' => ['from' => '2003', 'to' => null],
        ], $log->changes);
    }

    public function test_manager_does_not_see_contact_logs_button_or_access(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'gestor@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'group_role' => User::ROLE_MANAGER,
            'permission_key' => 'contacts.manage',
            'granted_by_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->get(ContactResource::getUrl())
            ->assertOk()
            ->assertDontSee('Ver logs', false);

        $this->actingAs($manager)
            ->get(ContactResource::getUrl('logs'))
            ->assertForbidden();
    }
}
