<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MotorflashLineErrorsPage;
use App\Filament\Pages\MotorflashMessageErrorsPage;
use App\Models\AdminPermissionGrant;
use App\Models\MotorflashLineIncident;
use App\Models\MotorflashLineIncidentHistory;
use App\Models\User;
use App\Services\MotorflashLineStatusReportIngestionService;
use App\Services\MotorflashLineStatusReportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MotorflashLineErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_valid_report_is_parsed_idempotently_and_only_error_lines_are_stored(): void
    {
        $rawEmail = $this->reportEmail(problemCount: 1, rows: [
            ['company' => 'HR MOTOR - Ana', 'phone' => '+34600123456', 'status' => 'No encontrada', 'detail' => 'The line no longer exists in Meta.', 'since' => '10/11/2026 08:15'],
            ['company' => 'HR MOTOR - Luis', 'phone' => '+34600987654', 'status' => 'OK', 'detail' => '+34 600 98 76 54 (Luis)', 'since' => '10/11/2026 08:15'],
        ]);

        $this->assertSame('created', $this->ingest($rawEmail));
        $this->assertDatabaseCount('motorflash_line_incidents', 1);
        $this->assertDatabaseCount('motorflash_line_incident_history', 1);
        $this->assertDatabaseHas('motorflash_line_incidents', [
            'phone_normalized' => '34600123456',
            'line_name' => 'HR MOTOR - Ana',
            'line_since_at' => '2026-11-10 08:15:00',
        ]);
        $this->assertDatabaseHas('motorflash_line_incident_history', [
            'phone_original' => '+34600123456',
            'line_name' => 'HR MOTOR - Ana',
        ]);

        $this->assertSame('duplicate', $this->ingest($rawEmail));

        $this->assertDatabaseCount('motorflash_line_incidents', 1);
        $this->assertDatabaseCount('motorflash_line_incident_history', 1);
    }

    public function test_complete_ok_report_closes_only_explicitly_ok_active_lines(): void
    {
        $errorReport = $this->reportEmail(problemCount: 1, rows: [
            ['company' => 'HR MOTOR - Ana', 'phone' => '+34600123456', 'status' => 'No encontrada', 'detail' => 'The line no longer exists in Meta.', 'since' => '10/11/2026 08:15'],
            ['company' => 'HR MOTOR - Luis', 'phone' => '+34600987654', 'status' => 'OK', 'detail' => '+34 600 98 76 54 (Luis)', 'since' => '10/11/2026 08:15'],
        ], subjectSuffix: '1');
        $okReport = $this->reportEmail(problemCount: 0, rows: [
            ['company' => 'HR MOTOR - Ana', 'phone' => '+34600123456', 'status' => 'OK', 'detail' => '+34 600 12 34 56 (Ana)', 'since' => '10/11/2026 09:15'],
            ['company' => 'HR MOTOR - Luis', 'phone' => '+34600987654', 'status' => 'OK', 'detail' => '+34 600 98 76 54 (Luis)', 'since' => '10/11/2026 09:15'],
        ], subjectSuffix: '2');

        $this->assertSame('created', $this->ingest($errorReport));
        $this->assertSame('created', $this->ingest($okReport));

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'phone_normalized' => '34600123456',
            'status' => MotorflashLineIncident::STATUS_CLOSED,
        ]);
        $this->assertDatabaseHas('motorflash_line_incident_history', [
            'state' => MotorflashLineIncidentHistory::STATE_CLOSED,
        ]);
    }

    public function test_todo_correcto_report_is_accepted_and_processes_ok_rows(): void
    {
        $rawEmail = $this->reportEmail(problemCount: 0, rows: [
            ['company' => 'HR MOTOR - Ana', 'phone' => '+34600123456', 'status' => 'OK', 'detail' => '+34 600 12 34 56 (Ana)', 'since' => '10/11/2026 15:40'],
        ], subject: 'Todo correcto - 1 líneas sin problemas');

        $this->assertSame('created', $this->ingest($rawEmail));
        $this->assertDatabaseCount('motorflash_line_incidents', 0);
        $this->assertDatabaseCount('motorflash_line_report_receipts', 1);
    }

    public function test_manage_permission_can_mark_a_line_in_process(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        $this->grant($user, 'motorflash-message-errors.manage');
        $incident = MotorflashLineIncident::query()->create([
            'error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED,
            'phone_normalized' => '34600123456',
            'phone_original' => '+34600123456',
            'status' => MotorflashLineIncident::STATUS_OPEN,
            'last_error_at' => now(),
        ]);

        Livewire::actingAs($user);
        Livewire::test(MotorflashLineErrorsPage::class)
            ->callTableAction('markInProcess', $incident);

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'id' => $incident->id,
            'status' => MotorflashLineIncident::STATUS_IN_PROCESS,
            'in_process_by_user_id' => $user->id,
        ]);

        Livewire::test(MotorflashLineErrorsPage::class)
            ->callTableAction('markInProcess', $incident->fresh());

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'id' => $incident->id,
            'status' => MotorflashLineIncident::STATUS_OPEN,
            'in_process_at' => null,
            'in_process_by_user_id' => null,
        ]);
        $this->assertDatabaseCount('motorflash_line_incident_history', 1);
    }

    public function test_processed_local_report_is_visible_from_the_dashboard_category_and_breadcrumbs(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');

        $this->ingest($this->reportEmail(problemCount: 1, rows: [
            ['company' => 'HR MOTOR - Ana', 'phone' => '+34600123456', 'status' => 'No encontrada', 'detail' => 'The line no longer exists in Meta.', 'since' => '10/11/2026 08:15'],
        ]));

        $this->actingAs($user)
            ->get(MotorflashMessageErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('Líneas desconectadas de Meta')
            ->assertSee('>1<', false)
            ->assertSee('Escritorio');

        $this->get(MotorflashLineErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('+34600123456')
            ->assertSee('Nombre de línea en Meta')
            ->assertSee('Desde')
            ->assertDontSee('Veces con error')
            ->assertSee('Motorflash Message')
            ->assertSee('Líneas desconectadas de Meta');
    }

    private function reportEmail(int $problemCount, array $rows, string $subjectSuffix = '1', ?string $subject = null): string
    {
        $total = count($rows);
        $subject ??= $problemCount === 0
            ? "Todo correcto - {$total} líneas sin problemas"
            : "[ESTADO LINEAS] {$problemCount} de {$total} líneas con problemas";
        $rowHtml = collect($rows)->map(fn (array $row): string => sprintf(
            '<tr><td>%s</td><td>%s</td><td><b>%s</b></td><td>%s</td><td>%s</td></tr>',
            $row['company'],
            $row['phone'],
            $row['status'],
            $row['detail'],
            $row['since'],
        ))->implode('');

        return "From: forwarder@example.com\r\nX-Forwarded-Message-Id: <original-{$subjectSuffix}@motorflash.com>\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<html><body><table><tr><th>Asunto: </th><td>{$subject}</td></tr><tr><th>Fecha: </th><td>10 Nov 2026 09:15:00 +0100</td></tr><tr><th>De: </th><td>Motorflash - Message - Estado de lineas &lt;message@motorflash.com&gt;</td></tr></table><table><tr><th>Empresa</th><th>Línea</th><th>Estado</th><th>Detalle</th><th>Desde</th></tr>{$rowHtml}</table></body></html>";
    }

    private function ingest(string $rawEmail): string
    {
        $report = app(MotorflashLineStatusReportParser::class)->parse($rawEmail);

        return app(MotorflashLineStatusReportIngestionService::class)->ingest($report);
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
