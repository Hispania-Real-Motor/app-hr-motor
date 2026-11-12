<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MotorflashLineErrorsPage;
use App\Filament\Pages\MotorflashMessageErrorsPage;
use App\Exceptions\InvalidMotorflashLineReportException;
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

    public function test_complete_report_with_disconnected_status_counts_both_error_rows(): void
    {
        $rows = collect(range(1, 62))->map(fn (int $number): array => [
            'company' => "HR MOTOR - Linea {$number}",
            'phone' => '+346000'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'status' => $number === 1 ? 'No encontrada' : ($number === 2 ? 'Desconectada' : 'OK'),
            'detail' => $number <= 2 ? 'The line no longer exists in Meta.' : '+34 600 00 00 00',
            'since' => '10/11/2026 15:40',
        ])->all();

        $report = app(MotorflashLineStatusReportParser::class)->parse(
            $this->reportEmail(problemCount: 2, rows: $rows, subject: '[ESTADO LINEAS] 2 de 62 líneas con problemas'),
        );

        $this->assertCount(62, $report['rows']);
        $this->assertSame(2, $report['problem_lines']);
        $this->assertCount(2, array_filter($report['rows'], fn (array $row): bool => $row['is_error']));
    }

    public function test_complete_report_closes_an_active_line_that_is_absent(): void
    {
        $initialRows = $this->completeRows([
            1 => ['status' => 'No encontrada', 'detail' => 'The line no longer exists in Meta.'],
        ]);
        $replacementRows = $this->completeRows();
        $replacementRows[0]['phone'] = '+34609999999';

        $this->assertSame('created', $this->ingest($this->reportEmail(1, $initialRows, subject: '[ESTADO LINEAS] 1 de 62 líneas con problemas')));
        $this->assertSame('created', $this->ingest($this->reportEmail(0, $replacementRows, subject: 'Todo correcto - 62 líneas sin problemas')));

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'phone_normalized' => '34600000001',
            'status' => MotorflashLineIncident::STATUS_CLOSED,
        ]);
        $this->assertDatabaseHas('motorflash_line_incident_history', [
            'state' => MotorflashLineIncidentHistory::STATE_CLOSED,
            'detail' => 'Línea ausente en un informe completo.',
        ]);
    }

    public function test_incomplete_report_does_not_close_active_lines(): void
    {
        $initialRows = $this->completeRows([
            1 => ['status' => 'No encontrada', 'detail' => 'The line no longer exists in Meta.'],
        ]);
        $incompleteRows = array_slice($this->completeRows(), 0, 61);

        $this->assertSame('created', $this->ingest($this->reportEmail(1, $initialRows, subject: '[ESTADO LINEAS] 1 de 62 líneas con problemas')));

        try {
            app(MotorflashLineStatusReportParser::class)->parse(
                $this->reportEmail(0, $incompleteRows, subject: 'Todo correcto - 62 líneas sin problemas'),
            );
            $this->fail('El informe incompleto debería haber sido rechazado.');
        } catch (InvalidMotorflashLineReportException) {
            // La validación debe detenerse antes de llegar a la reconciliación.
        }

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'phone_normalized' => '34600000001',
            'status' => MotorflashLineIncident::STATUS_OPEN,
        ]);
        $this->assertDatabaseCount('motorflash_line_incident_history', 1);
    }

    public function test_closed_line_reopens_with_updated_error_reason(): void
    {
        $errorRows = $this->completeRows([
            1 => ['status' => 'No encontrada', 'detail' => 'The line no longer exists in Meta.'],
        ]);
        $missingRows = $this->completeRows();
        $missingRows[0]['phone'] = '+34609999999';
        $reopenedRows = $this->completeRows([
            1 => ['status' => 'Desconectada', 'detail' => 'The line is disconnected in Meta.'],
        ]);

        $this->assertSame('created', $this->ingest($this->reportEmail(1, $errorRows, subject: '[ESTADO LINEAS] 1 de 62 líneas con problemas')));
        $this->assertSame('created', $this->ingest($this->reportEmail(0, $missingRows, subject: 'Todo correcto - 62 líneas sin problemas')));
        $this->assertSame('created', $this->ingest($this->reportEmail(1, $reopenedRows, subjectSuffix: 'reopened', subject: '[ESTADO LINEAS] 1 de 62 líneas con problemas')));

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'phone_normalized' => '34600000001',
            'status' => MotorflashLineIncident::STATUS_OPEN,
            'error_reason' => 'Desconectada',
        ]);
        $this->assertDatabaseHas('motorflash_line_incident_history', [
            'state' => MotorflashLineIncidentHistory::STATE_REOPENED,
        ]);
    }

    public function test_in_process_line_remains_in_process_when_reported_with_error(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        $this->grant($user, 'motorflash-message-errors.manage');
        $incident = MotorflashLineIncident::query()->create([
            'error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED,
            'phone_normalized' => '34600000001',
            'phone_original' => '+34600000001',
            'status' => MotorflashLineIncident::STATUS_OPEN,
        ]);

        Livewire::actingAs($user);
        Livewire::test(MotorflashLineErrorsPage::class)->callTableAction('markInProcess', $incident);

        $rows = $this->completeRows([
            1 => ['status' => 'Desconectada', 'detail' => 'The line is disconnected in Meta.'],
        ]);
        $this->assertSame('created', $this->ingest($this->reportEmail(1, $rows, subjectSuffix: 'in-process', subject: '[ESTADO LINEAS] 1 de 62 líneas con problemas')));

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'id' => $incident->id,
            'status' => MotorflashLineIncident::STATUS_IN_PROCESS,
            'error_reason' => 'Desconectada',
        ]);
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

    public function test_manage_permission_can_delete_a_line_incident_and_its_history(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        $this->grant($user, 'motorflash-message-errors.manage');
        $incident = MotorflashLineIncident::query()->create([
            'error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED,
            'phone_normalized' => '34600999999',
            'phone_original' => '+34600999999',
            'status' => MotorflashLineIncident::STATUS_IN_PROCESS,
        ]);
        MotorflashLineIncidentHistory::query()->create([
            'incident_id' => $incident->id,
            'state' => MotorflashLineIncidentHistory::STATE_ERROR,
            'phone_original' => '+34600999999',
            'occurred_at' => now(),
        ]);

        Livewire::actingAs($user);
        Livewire::test(MotorflashLineErrorsPage::class)
            ->callTableAction('deleteGroup', $incident);

        $this->assertDatabaseMissing('motorflash_line_incidents', ['id' => $incident->id]);
        $this->assertDatabaseMissing('motorflash_line_incident_history', ['incident_id' => $incident->id]);
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
            ->assertSee('1')
            ->assertSee('Escritorio');

        $this->get(MotorflashLineErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('+34600123456')
            ->assertSee('Empresa en Motorflash')
            ->assertDontSee('Nombre de línea en Meta')
            ->assertSee('Motivo')
            ->assertSee('Desde')
            ->assertDontSee('Estado')
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

    private function completeRows(array $overrides = []): array
    {
        return collect(range(1, 62))->map(function (int $number) use ($overrides): array {
            return [
                'company' => "HR MOTOR - Linea {$number}",
                'phone' => '+346000'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'status' => $overrides[$number]['status'] ?? 'OK',
                'detail' => $overrides[$number]['detail'] ?? '+34 600 00 00 00',
                'since' => '10/11/2026 15:40',
            ];
        })->all();
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
