<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MotorflashCommercialErrorsPage;
use App\Models\AdminPermissionGrant;
use App\Models\MotorflashCommercialErrorEvent;
use App\Models\MotorflashCommercialErrorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MotorflashCommercialErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['internal.motorflash_commercial_errors.secret' => 'test-secret']);
    }

    public function test_endpoint_requires_its_independent_secret(): void
    {
        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload())
            ->assertUnauthorized();
    }

    public function test_endpoint_stores_a_normalized_event_idempotently(): void
    {
        $payload = $this->payload([
            'searched_email' => "  SALES@EXAMPLE.COM  ",
        ]);

        $this->postJson('/api/internal/motorflash/commercial-errors', $payload, $this->headers())
            ->assertCreated()
            ->assertJson(['status' => 'created']);

        $this->postJson('/api/internal/motorflash/commercial-errors', $payload, $this->headers())
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('motorflash_commercial_error_events', 1);
        $this->assertDatabaseCount('motorflash_commercial_error_receipts', 1);
        $this->assertDatabaseHas('motorflash_commercial_error_events', [
            'searched_email' => 'SALES@EXAMPLE.COM',
            'searched_email_normalized' => 'sales@example.com',
        ]);
    }

    public function test_fifty_distinct_events_are_one_group_with_fifty_occurrences(): void
    {
        for ($index = 1; $index <= 50; $index++) {
            $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload([
                'source_event_id' => 'event-'.$index,
                'searched_email' => 'sales@example.com',
            ]), $this->headers())->assertCreated();
        }

        $this->assertDatabaseCount('motorflash_commercial_error_events', 50);

        $this->assertSame(1, MotorflashCommercialErrorEvent::query()
            ->where('searched_email_normalized', 'sales@example.com')
            ->groupBy('searched_email_normalized')
            ->get()
            ->count());
        $this->assertSame(50, MotorflashCommercialErrorEvent::query()
            ->where('searched_email_normalized', 'sales@example.com')
            ->count());
    }

    public function test_equivalent_email_formats_are_grouped(): void
    {
        $cases = [
            ['source_event_id' => 'email-1', 'searched_email' => ' SALES@EXAMPLE.COM '],
            ['source_event_id' => 'email-2', 'searched_email' => 'sales@example.com'],
        ];

        foreach ($cases as $case) {
            $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload($case), $this->headers())
                ->assertCreated();
        }

        $this->assertSame(1, MotorflashCommercialErrorEvent::query()
            ->select('searched_email_normalized')
            ->groupBy('searched_email_normalized')
            ->get()
            ->count());
        $this->assertDatabaseHas('motorflash_commercial_error_events', [
            'searched_email_normalized' => 'sales@example.com',
        ]);
    }

    public function test_endpoint_rejects_an_invalid_email(): void
    {
        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload([
            'searched_email' => 'not-an-email',
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['searched_email']);
    }

    public function test_endpoint_rejects_unknown_error_types(): void
    {
        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload([
            'error_type' => 'other_error',
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['error_type']);
    }

    public function test_filament_groups_events_by_normalized_identifier_and_protects_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->actingAs($user)
            ->get(MotorflashCommercialErrorsPage::getUrl())
            ->assertForbidden();

        $this->grant($user, 'motorflash-message-errors.view');
        foreach ([
            [
                'error_type' => MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE,
                'searched_email' => 'sales@example.com',
                'searched_email_normalized' => 'sales@example.com',
                'occurred_at' => '2026-11-11 10:00:00',
                'source_event_id' => 'event-1',
                'source_workflow' => 'workflow-1',
            ],
            [
                'error_type' => MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE,
                'searched_email' => 'SALES@EXAMPLE.COM',
                'searched_email_normalized' => 'sales@example.com',
                'occurred_at' => '2026-11-11 11:00:00',
                'source_event_id' => 'event-2',
                'source_workflow' => 'workflow-1',
            ],
        ] as $event) {
            MotorflashCommercialErrorEvent::query()->create($event);
        }
        MotorflashCommercialErrorGroup::query()->create([
            'error_type' => MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE,
            'searched_email_normalized' => 'sales@example.com',
            'status' => MotorflashCommercialErrorGroup::STATUS_OPEN,
        ]);

        Livewire::actingAs($user);
        Livewire::test(MotorflashCommercialErrorsPage::class)
            ->assertSee('Correo buscado')
            ->assertSee('Ocurrencias')
            ->assertSee('Último error')
            ->assertSee('SALES@EXAMPLE.COM')
            ->assertSee('2');
    }

    public function test_manage_actions_toggle_group_state_and_keep_it_when_a_new_event_arrives(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        $this->grant($user, 'motorflash-message-errors.manage');

        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload(), $this->headers())
            ->assertCreated();

        $component = Livewire::actingAs($user)->test(MotorflashCommercialErrorsPage::class);
        $record = $component->instance()->getTableRecords()->first();

        $component->callTableAction('markInProcess', $record);

        $group = MotorflashCommercialErrorGroup::query()->firstOrFail();
        $this->assertSame(MotorflashCommercialErrorGroup::STATUS_IN_PROCESS, $group->status);
        $this->assertStringContainsString('bg-yellow', $component->html());

        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload([
            'source_event_id' => 'event-2',
            'searched_email' => 'SALES@EXAMPLE.COM',
        ]), $this->headers())->assertCreated();

        $this->assertSame(
            MotorflashCommercialErrorGroup::STATUS_IN_PROCESS,
            $group->fresh()->status,
        );

        $component = Livewire::actingAs($user)->test(MotorflashCommercialErrorsPage::class);
        $record = $component->instance()->getTableRecords()->first();
        $component->callTableAction('markInProcess', $record);

        $this->assertDatabaseHas('motorflash_commercial_error_groups', [
            'id' => $group->id,
            'status' => MotorflashCommercialErrorGroup::STATUS_OPEN,
            'in_process_at' => null,
            'in_process_by_user_id' => null,
        ]);
    }

    public function test_view_only_user_cannot_execute_manage_actions(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload(), $this->headers())
            ->assertCreated();

        $component = Livewire::actingAs($user)->test(MotorflashCommercialErrorsPage::class);

        $this->assertFalse(app_user_has_admin_permission($user, 'motorflash-message-errors.manage'));
        $this->assertStringNotContainsString('Marcar en proceso', $component->html());
        $this->assertStringNotContainsString('Eliminar', $component->html());
    }

    public function test_manage_can_delete_group_events_but_receipt_idempotency_remains(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        $this->grant($user, 'motorflash-message-errors.manage');
        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload(), $this->headers())
            ->assertCreated();

        $component = Livewire::actingAs($user)->test(MotorflashCommercialErrorsPage::class);
        $record = $component->instance()->getTableRecords()->first();
        $component->callTableAction('deleteGroup', $record);

        $this->assertDatabaseCount('motorflash_commercial_error_events', 0);
        $this->assertDatabaseCount('motorflash_commercial_error_groups', 0);
        $this->assertDatabaseCount('motorflash_commercial_error_receipts', 1);

        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload(), $this->headers())
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('motorflash_commercial_error_events', 0);

        $this->postJson('/api/internal/motorflash/commercial-errors', $this->payload([
            'source_event_id' => 'event-2',
        ]), $this->headers())->assertCreated();

        $this->assertDatabaseCount('motorflash_commercial_error_events', 1);
        $this->assertDatabaseCount('motorflash_commercial_error_groups', 1);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'error_type' => MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE,
            'searched_email' => 'sales@example.com',
            'occurred_at' => '2026-11-11T10:00:00+01:00',
            'source_event_id' => 'event-1',
            'source_workflow' => MotorflashCommercialErrorEvent::SOURCE_WORKFLOW_N8N,
        ], $overrides);
    }

    private function headers(): array
    {
        return ['X-Motorflash-Commercial-Errors-Secret' => 'test-secret'];
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
