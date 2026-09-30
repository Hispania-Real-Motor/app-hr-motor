<?php

namespace Tests\Feature;

use App\Models\Dealership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_users_in_agenda_show_extra_role_and_dealership_below_the_name(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'extra_role' => null,
        ]);

        $dealership = Dealership::factory()->create([
            'name' => 'Bilbao',
        ]);

        User::factory()->create([
            'name' => 'Laura Comercial',
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'dealership' => 'Bilbao',
            'dealership_id' => $dealership->id,
        ]);

        $response = $this->actingAs($admin)->get(route('agenda.index'));

        $response
            ->assertOk()
            ->assertSee('Laura Comercial')
            ->assertSee('Comercial · Bilbao', false)
            ->assertSee('Teléfono')
            ->assertSee('Extensión Enreach')
            ->assertDontSee('Telefono')
            ->assertDontSee('Extension Enreach');
    }
}
