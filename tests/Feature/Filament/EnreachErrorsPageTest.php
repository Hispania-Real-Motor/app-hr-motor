<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\EnreachErrorsPage;
use App\Filament\Pages\EnreachErrorPhoneDetailsPage;
use App\Filament\Pages\EnreachErrorPhonesPage;
use App\Filament\Widgets\MotorflashMessageErrorsOverviewWidget;
use App\Models\AdminPermissionGrant;
use App\Models\EnreachErrorEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class EnreachErrorsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_view_permission_controls_the_enreach_card_and_page_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertForbidden();

        $this->grant($user, 'enreach-errors.view');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('Enreach')
            ->assertSee('Errores IT')
            ->assertSee('#8203F7')
            ->assertSee('Estado: Pendiente de integración')
            ->assertDontSee('Motorflash Message');

        $this->get(EnreachErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('Tipos de incidencia detectados en Enreach.');
    }

    public function test_connected_enreach_shows_zero_and_real_counts(): void
    {
        config(['services.enreach.integration_status' => 'connected']);
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'enreach-errors.view');

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('Enreach')
            ->assertSee('0')
            ->assertDontSee('Pendiente de integración')
            ->assertDontSee('N/D');

        EnreachErrorEvent::query()->create([
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'searched_phone' => '+34 600 123 123',
            'occurred_at' => now(),
            'direction' => EnreachErrorEvent::DIRECTION_INCOMING,
            'other_party_phone' => '600999999',
        ]);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('1')
            ->assertDontSee('Pendiente de integración')
            ->assertDontSee('N/D');
    }

    public function test_connected_enreach_shows_nd_when_its_query_fails(): void
    {
        config(['services.enreach.integration_status' => 'connected']);
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'enreach-errors.view');
        Schema::dropIfExists('enreach_error_events');

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('N/D')
            ->assertDontSee('Pendiente de integración');
    }

    public function test_manage_permission_is_independent_from_view_permission(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->grant($user, 'enreach-errors.view');

        $this->assertTrue(app_user_has_admin_permission($user, 'enreach-errors.view'));
        $this->assertFalse(app_user_has_admin_permission($user, 'enreach-errors.manage'));

        $this->grant($user, 'enreach-errors.manage');

        $this->assertTrue(app_user_has_admin_permission($user, 'enreach-errors.manage'));
    }

    public function test_enreach_levels_group_by_normalized_phone_and_show_event_details(): void
    {
        config(['services.enreach.integration_status' => 'connected']);
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'enreach-errors.view');

        EnreachErrorEvent::query()->create([
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'searched_phone' => '+34 600 123 123',
            'occurred_at' => now()->subHour(),
            'direction' => EnreachErrorEvent::DIRECTION_INCOMING,
            'other_party_phone' => '600999999',
        ]);
        EnreachErrorEvent::query()->create([
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'searched_phone' => '34600123123',
            'occurred_at' => now(),
            'direction' => EnreachErrorEvent::DIRECTION_OUTGOING,
            'other_party_phone' => '600888888',
        ]);

        $this->actingAs($user)
            ->get(EnreachErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('Teléfono de comercial no encontrado en Salesforce')
            ->assertSee('1');

        $phone = EnreachErrorEvent::normalizePhone('+34 600 123 123');

        $this->get(EnreachErrorPhonesPage::getUrl([
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
        ]))
            ->assertOk()
            ->assertSee($phone)
            ->assertSee('2');

        $this->get(EnreachErrorPhoneDetailsPage::getUrl([
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'phone' => $phone,
        ]))
            ->assertOk()
            ->assertSee('Entrante')
            ->assertSee('Saliente')
            ->assertSee('600999999')
            ->assertSee('600888888');
    }

    public function test_nested_enreach_pages_require_the_view_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_MANAGER]))
            ->get(EnreachErrorPhonesPage::getUrl(['type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND]))
            ->assertForbidden();

        $this->get(EnreachErrorPhoneDetailsPage::getUrl([
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'phone' => '34600123123',
        ]))->assertForbidden();
    }

    public function test_manage_user_can_review_and_resolve_only_the_selected_type_and_phone(): void
    {
        config(['services.enreach.integration_status' => 'connected']);
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'enreach-errors.view');
        $this->grant($user, 'enreach-errors.manage');

        EnreachErrorEvent::query()->create([
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'searched_phone' => '+34 600 123 123',
            'occurred_at' => now(),
            'direction' => EnreachErrorEvent::DIRECTION_INCOMING,
            'other_party_phone' => null,
            'source_event_id' => 'review-call-1',
            'source_workflow' => 'test-workflow',
        ]);
        EnreachErrorEvent::query()->create([
            'error_type' => 'another_error_type',
            'searched_phone' => '+34 600 123 123',
            'occurred_at' => now(),
            'direction' => EnreachErrorEvent::DIRECTION_OUTGOING,
            'other_party_phone' => null,
            'source_event_id' => 'review-call-2',
            'source_workflow' => 'test-workflow',
        ]);

        $phone = EnreachErrorEvent::normalizePhone('+34 600 123 123');

        Livewire::actingAs($user);
        Livewire::test(EnreachErrorPhonesPage::class, [
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
        ])
            ->callTableAction('markReviewed', $phone);

        $this->assertDatabaseHas('enreach_error_incidence_states', [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'phone_normalized' => $phone,
        ]);

        Livewire::test(EnreachErrorPhonesPage::class, [
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
        ])
            ->callTableAction('markReviewed', $phone);

        $this->assertDatabaseMissing('enreach_error_incidence_states', [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'phone_normalized' => $phone,
        ]);

        Livewire::test(EnreachErrorPhonesPage::class, [
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
        ])
            ->callTableAction('markReviewed', $phone);

        Livewire::test(EnreachErrorPhonesPage::class, [
            'type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
        ])
            ->callTableAction('resolve', $phone);

        $this->assertDatabaseMissing('enreach_error_events', [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'phone_normalized' => $phone,
        ]);
        $this->assertDatabaseHas('enreach_error_events', [
            'error_type' => 'another_error_type',
            'phone_normalized' => $phone,
        ]);
        $this->assertDatabaseMissing('enreach_error_incidence_states', [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND,
            'phone_normalized' => $phone,
        ]);
    }

    public function test_resolved_event_cannot_be_recreated_by_an_old_n8n_retry(): void
    {
        config(['internal.enreach_events.secret' => 'staging-secret']);

        $payload = [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE,
            'searched_phone' => '+34 600 123 123',
            'occurred_at' => '2026-11-09T12:30:00+01:00',
            'direction' => 'IN',
            'source_event_id' => 'resolved-call-1',
            'source_workflow' => 'enreach-inbound-errors',
        ];

        $this->withHeader('X-Enreach-Secret', 'staging-secret')
            ->postJson('/api/internal/enreach/errors', $payload)
            ->assertCreated();

        EnreachErrorEvent::query()->where('source_event_id', 'resolved-call-1')->delete();

        $this->withHeader('X-Enreach-Secret', 'staging-secret')
            ->postJson('/api/internal/enreach/errors', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('enreach_error_events', 0);
        $this->assertDatabaseHas('enreach_error_event_receipts', [
            'source_event_id' => 'resolved-call-1',
            'source_workflow' => 'enreach-inbound-errors',
        ]);
    }

    public function test_enreach_event_endpoint_requires_secret_and_stores_a_normalized_event(): void
    {
        config(['internal.enreach_events.secret' => 'staging-secret']);

        $payload = [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE,
            'searched_phone' => '+34 600 123 123',
            'occurred_at' => '2026-11-09T12:30:00+01:00',
            'direction' => 'IN',
            'other_party_phone' => null,
            'source_event_id' => 'call-123',
            'source_workflow' => 'enreach-inbound-errors',
        ];

        $this->postJson('/api/internal/enreach/errors', $payload)
            ->assertUnauthorized();

        $this->withHeader('X-Enreach-Secret', 'staging-secret')
            ->postJson('/api/internal/enreach/errors', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'created');

        $this->assertDatabaseHas('enreach_error_events', [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE,
            'searched_phone' => '+34 600 123 123',
            'phone_normalized' => '34600123123',
            'direction' => EnreachErrorEvent::DIRECTION_INCOMING,
            'source_event_id' => 'call-123',
            'source_workflow' => 'enreach-inbound-errors',
        ]);
    }

    public function test_repeated_enreach_event_is_idempotent_per_workflow_and_source_event(): void
    {
        config(['internal.enreach_events.secret' => 'staging-secret']);

        $payload = [
            'error_type' => EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND_IN_SALESFORCE,
            'searched_phone' => '600 123 123',
            'occurred_at' => '2026-11-09T12:30:00Z',
            'direction' => 'OUT',
            'source_event_id' => 'call-456',
            'source_workflow' => 'enreach-outbound-errors',
        ];

        $this->withHeader('X-Enreach-Secret', 'staging-secret')
            ->postJson('/api/internal/enreach/errors', $payload)
            ->assertCreated();

        $this->withHeader('X-Enreach-Secret', 'staging-secret')
            ->postJson('/api/internal/enreach/errors', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('enreach_error_events', 1);
    }

    private function grant(User $user, string $permissionKey): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permissionKey,
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
