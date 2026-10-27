<?php

namespace Tests\Feature;

use App\Jobs\ProcessProvisioningRequestJob;
use App\Jobs\ProcessProvisioningStepJob;
use App\Models\AdminPermissionGrant;
use App\Models\ProvisioningRequest;
use App\Models\ProvisioningStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_all_module_routes_are_forbidden_without_tools_permission(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $request = ProvisioningRequest::query()->create([
            'type' => ProvisioningRequest::TYPE_ONBOARDING,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'requested_by_user_id' => $user->id,
            'employee_data' => ['name' => 'Sin permiso'],
        ]);

        $this->actingAs($user);
        $this->get(route('tools.altas-bajas'))->assertForbidden();
        $this->get(route('tools.altas-bajas.create'))->assertForbidden();
        $this->post(route('tools.altas-bajas.store'))->assertForbidden();
        $this->get(route('tools.altas-bajas.show', $request))->assertForbidden();
        $this->get(route('tools.altas-bajas.status', $request))->assertForbidden();
    }

    public function test_module_index_shows_construction_notice(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('tools.altas-bajas'))
            ->assertOk()
            ->assertSee('Página en construcción')
            ->assertDontSee('Nueva alta');
    }

    public function test_authorized_user_can_open_form_and_create_one_active_step_with_snapshot(): void
    {
        Queue::fake();
        $actor = $this->authorizedUser();
        $this->actingAs($actor);

        $this->get(route('tools.altas-bajas.create'))->assertOk()->assertSee('Nueva alta');
        $response = $this->post(route('tools.altas-bajas.store'), $this->validPayload());

        $request = ProvisioningRequest::query()->firstOrFail();
        $response->assertRedirect(route('tools.altas-bajas.show', $request));
        $this->assertSame($actor->id, $request->requested_by_user_id);
        $this->assertSame('Ana Alta', data_get($request->employee_data, 'name'));
        $this->assertCount(1, $request->steps);
        $this->assertSame('app_hr_motor', $request->steps->first()->platform);
        Queue::assertPushed(ProcessProvisioningRequestJob::class);
    }

    public function test_validation_keeps_required_and_conditional_fields(): void
    {
        $actor = $this->authorizedUser();
        $this->actingAs($actor);

        $this->from(route('tools.altas-bajas.create'))
            ->post(route('tools.altas-bajas.store'), ['email' => 'incorrecto'])
            ->assertRedirect(route('tools.altas-bajas.create'))
            ->assertSessionHasErrors(['name', 'company_entry_date', 'job_position']);

        $payload = $this->validPayload(['extra_role' => User::ROLE_INFORMATION_TECHNOLOGY]);
        $this->post(route('tools.altas-bajas.store'), $payload)->assertSessionHasErrors([
            'it_monday_start', 'it_monday_end', 'it_friday_start', 'it_friday_end',
        ]);
    }

    public function test_processing_creates_user_links_it_and_completes_step(): void
    {
        Notification::fake();
        Password::shouldReceive('broker->sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        $actor = $this->authorizedUser();
        $request = ProvisioningRequest::query()->create([
            'type' => ProvisioningRequest::TYPE_ONBOARDING,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'requested_by_user_id' => $actor->id,
            'employee_data' => $this->validPayload(),
        ]);
        $step = $request->steps()->create(['platform' => 'app_hr_motor', 'status' => ProvisioningStep::STATUS_PENDING]);

        (new ProcessProvisioningStepJob($step->id))->handle();

        $request->refresh();
        $step->refresh();
        $this->assertSame(ProvisioningStep::STATUS_COMPLETED, $step->status);
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame(100, $request->progress);
        $this->assertNotNull($request->provisioned_user_id);
        $this->assertDatabaseHas('users', ['id' => $request->provisioned_user_id, 'is_active' => 0, 'must_change_password' => 1]);
    }

    public function test_invitation_failure_keeps_user_and_marks_request_failed_at_one_hundred_percent(): void
    {
        Password::shouldReceive('broker->sendResetLink')->once()->andReturn(Password::INVALID_USER);
        $actor = $this->authorizedUser();
        $request = ProvisioningRequest::query()->create([
            'type' => ProvisioningRequest::TYPE_ONBOARDING,
            'status' => ProvisioningRequest::STATUS_PENDING,
            'requested_by_user_id' => $actor->id,
            'employee_data' => $this->validPayload(),
        ]);
        $step = $request->steps()->create(['platform' => 'app_hr_motor', 'status' => ProvisioningStep::STATUS_PENDING]);

        (new ProcessProvisioningStepJob($step->id))->handle();

        $request->refresh();
        $step->refresh();
        $this->assertNotNull($request->provisioned_user_id);
        $this->assertSame(ProvisioningStep::STATUS_FAILED, $step->status);
        $this->assertSame('El usuario se ha creado, pero no se ha podido enviar el correo de activación.', $step->message);
        $this->assertSame(ProvisioningRequest::STATUS_FAILED, $request->status);
        $this->assertSame(100, $request->progress);
    }

    public function test_duplicate_step_job_does_not_create_another_user(): void
    {
        Password::shouldReceive('broker->sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        Notification::fake();
        $actor = $this->authorizedUser();
        $request = ProvisioningRequest::query()->create(['type' => ProvisioningRequest::TYPE_ONBOARDING, 'status' => ProvisioningRequest::STATUS_PENDING, 'requested_by_user_id' => $actor->id, 'employee_data' => $this->validPayload()]);
        $step = $request->steps()->create(['platform' => 'app_hr_motor', 'status' => ProvisioningStep::STATUS_PENDING]);

        $job = new ProcessProvisioningStepJob($step->id);
        $job->handle();
        $job->handle();

        $this->assertSame(1, User::query()->where('email', 'ana.alta@example.com')->count());
    }

    public function test_progress_counts_all_terminal_steps_and_status_endpoint_hides_technical_data(): void
    {
        $actor = $this->authorizedUser();
        $request = ProvisioningRequest::query()->create(['type' => ProvisioningRequest::TYPE_ONBOARDING, 'status' => ProvisioningRequest::STATUS_PROCESSING, 'requested_by_user_id' => $actor->id, 'employee_data' => ['name' => 'Ana Alta']]);
        $request->steps()->createMany([
            ['platform' => 'app_hr_motor', 'status' => ProvisioningStep::STATUS_COMPLETED],
            ['platform' => 'future', 'status' => ProvisioningStep::STATUS_FAILED, 'technical_data' => ['secret' => 'hidden']],
            ['platform' => 'future-two', 'status' => ProvisioningStep::STATUS_SKIPPED],
            ['platform' => 'future-three', 'status' => ProvisioningStep::STATUS_PENDING],
        ]);
        $this->assertSame(75, $request->refresh()->progress);

        $this->actingAs($actor)->getJson(route('tools.altas-bajas.status', $request))
            ->assertOk()->assertJsonPath('progress', 75)->assertJsonMissingPath('steps.1.technical_data');
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        AdminPermissionGrant::query()->create(['permission_key' => 'tools.manage', 'user_id' => $user->id, 'is_revoked' => false]);
        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Alta', 'email' => 'ana.alta@example.com', 'company_entry_date' => '2026-10-01', 'job_position' => 'Analista', 'role' => User::ROLE_USER, 'extra_role' => null, 'phone' => '600123123', 'enreach_extension' => null, 'dealership_id' => null,
        ], $overrides);
    }
}
