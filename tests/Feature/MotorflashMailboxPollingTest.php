<?php

namespace Tests\Feature;

use App\Models\MotorflashMailboxCursor;
use App\Services\MotorflashMailboxImapClient;
use App\Services\MotorflashMailboxPollingService;
use App\Services\MotorflashLineStatusReportIngestionService;
use App\Services\MotorflashLineStatusReportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MotorflashMailboxPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_ignores_a_large_backlog_of_unrelated_mail(): void
    {
        $this->fakeMailbox([101 => $this->reportEmail('17 Nov 2026 09:00:00 +0100')]);

        $result = app(MotorflashMailboxPollingService::class)->poll();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(101, MotorflashMailboxCursor::query()->value('last_uid'));
    }

    public function test_pending_reports_are_reconciled_in_report_date_order(): void
    {
        $this->fakeMailbox([
            101 => $this->reportEmail('17 Nov 2026 09:00:00 +0100', 'No encontrada', 'The line no longer exists in Meta.', 'first'),
            102 => $this->reportEmail('17 Nov 2026 10:00:00 +0100', 'OK', '+34 600 12 34 56', 'second'),
        ]);

        $this->assertSame(2, app(MotorflashMailboxPollingService::class)->poll()['processed']);

        $this->assertDatabaseHas('motorflash_line_incidents', [
            'phone_normalized' => '34600123456',
            'status' => 'closed',
        ]);
    }

    public function test_duplicate_is_moved_and_cursor_advances_without_reconciling_twice(): void
    {
        $email = $this->reportEmail('17 Nov 2026 09:00:00 +0100');
        $report = app(MotorflashLineStatusReportParser::class)->parse($email);
        app(MotorflashLineStatusReportIngestionService::class)->ingest($report);
        $this->fakeMailbox([102 => $email]);

        $this->assertSame(1, app(MotorflashMailboxPollingService::class)->poll()['duplicates']);
        $this->assertSame(102, MotorflashMailboxCursor::query()->value('last_uid'));
        $this->assertDatabaseCount('motorflash_line_report_receipts', 1);
    }

    public function test_invalid_report_advances_and_later_valid_report_is_processed(): void
    {
        $this->fakeMailbox([
            101 => 'From: forwarder@example.com\r\n\r\nnot a report',
            102 => $this->reportEmail('17 Nov 2026 09:00:00 +0100'),
        ]);

        $result = app(MotorflashMailboxPollingService::class)->poll();

        $this->assertSame(1, $result['invalid']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(102, MotorflashMailboxCursor::query()->value('last_uid'));
        $this->assertDatabaseCount('motorflash_line_report_receipts', 1);
    }

    public function test_first_cursor_start_does_not_reconcile_an_older_pending_report(): void
    {
        $current = $this->reportEmail('17 Nov 2026 12:00:00 +0100', 'OK', '+34 600 12 34 56', 'current');
        $currentReport = app(MotorflashLineStatusReportParser::class)->parse($current);
        app(MotorflashLineStatusReportIngestionService::class)->ingest($currentReport);

        $this->fakeMailbox([
            15858 => $this->reportEmail('16 Nov 2026 12:00:00 +0100', 'No encontrada', 'The line no longer exists in Meta.', 'old'),
        ], startUid: 15858);

        $result = app(MotorflashMailboxPollingService::class)->poll();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(15858, MotorflashMailboxCursor::query()->value('last_uid'));
        $this->assertDatabaseCount('motorflash_line_incidents', 0);
        $this->assertDatabaseCount('motorflash_line_report_receipts', 1);
    }

    /** @param array<int, string> $messages */
    private function fakeMailbox(array $messages, int $startUid = 101): void
    {
        config()->set([
            'motorflash_mailbox.enabled' => true,
            'motorflash_mailbox.allow_non_production' => true,
            'motorflash_mailbox.host' => 'imap.test',
            'motorflash_mailbox.username' => 'user',
            'motorflash_mailbox.password' => 'secret',
            'motorflash_mailbox.start_uid' => $startUid,
        ]);

        $imap = Mockery::mock(MotorflashMailboxImapClient::class);
        $stream = new \stdClass();
        $imap->shouldReceive('open')->once()->andReturn($stream);
        $imap->shouldReceive('search')->once()->with($stream, Mockery::on(fn (string $criteria): bool => str_contains($criteria, 'SUBJECT "[ESTADO LINEAS]"')))->andReturn(array_keys($messages));
        foreach ($messages as $uid => $raw) {
            $imap->shouldReceive('overview')->once()->with($stream, $uid)->andReturn((object) ['subject' => '[ESTADO LINEAS]']);
            $imap->shouldReceive('raw')->once()->with($stream, $uid)->andReturn($raw);
            if (str_contains($raw, 'not a report') === false) {
                $imap->shouldReceive('moveToProcessed')->once()->with($stream, $uid, 'Procesados');
            }
        }
        $imap->shouldReceive('close')->once()->with($stream);
        $this->app->instance(MotorflashMailboxImapClient::class, $imap);
    }

    private function reportEmail(string $date, string $status = 'OK', string $detail = '+34 600 12 34 56', string $id = 'report'): string
    {
        $problemCount = $status === 'OK' ? 0 : 1;

        return "From: forwarder@example.com\r\nX-Forwarded-Message-Id: <{$id}@motorflash.com>\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . "<table><tr><th>Asunto: </th><td>[ESTADO LINEAS] {$problemCount} de 1 líneas con problemas</td></tr>"
            . "<tr><th>Fecha: </th><td>{$date}</td></tr><tr><th>De: </th><td>Motorflash &lt;message@motorflash.com&gt;</td></tr></table>"
            . "<table><tr><th>Empresa</th><th>Línea</th><th>Estado</th><th>Detalle</th><th>Desde</th></tr><tr><td>HR MOTOR</td><td>+34600123456</td><td>{$status}</td><td>{$detail}</td><td>17/11/2026 09:00</td></tr></table>";
    }
}
