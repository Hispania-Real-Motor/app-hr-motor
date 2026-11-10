<?php

namespace App\Services;

use App\Models\MotorflashLineIncident;
use App\Models\MotorflashLineIncidentHistory;
use App\Models\MotorflashLineReportReceipt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MotorflashLineStatusReportIngestionService
{
    public function ingest(array $report): string
    {
        try {
            return DB::transaction(function () use ($report): string {
                if (MotorflashLineReportReceipt::query()
                    ->where('fingerprint', $report['fingerprint'])
                    ->lockForUpdate()
                    ->exists()) {
                    return 'duplicate';
                }

                MotorflashLineReportReceipt::query()->create([
                    'fingerprint' => $report['fingerprint'],
                    'original_message_id' => $report['original_message_id'],
                    'original_subject' => $report['original_subject'],
                    'report_occurred_at' => $report['report_occurred_at'],
                    'total_lines' => $report['total_lines'],
                    'problem_lines' => $report['problem_lines'],
                    'received_at' => now(),
                ]);

                foreach ($report['rows'] as $row) {
                    if ($row['is_error']) {
                        $this->reconcileErrorRow($report, $row);
                    } else {
                        $this->closeExplicitOkRow($report, $row);
                    }
                }

                return 'created';
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $row */
    private function reconcileErrorRow(array $report, array $row): void
    {
        $incident = MotorflashLineIncident::query()
            ->where('error_type', MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED)
            ->where('phone_normalized', $row['phone_normalized'])
            ->lockForUpdate()
            ->first();

        $wasClosed = $incident?->status === MotorflashLineIncident::STATUS_CLOSED;

        if ($incident === null) {
            $incident = new MotorflashLineIncident([
                'error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED,
                'phone_normalized' => $row['phone_normalized'],
                'phone_original' => $row['phone_original'],
                'line_name' => $row['line_name'],
                'status' => MotorflashLineIncident::STATUS_OPEN,
                'first_error_at' => $row['occurred_at'],
            ]);
        }

        $incident->fill([
            'phone_original' => $row['phone_original'],
            'line_name' => $row['line_name'],
            'company' => $row['company'],
            'last_error_detail' => $row['detail'],
            'last_error_at' => $row['occurred_at'],
            'line_since_at' => $row['line_since_at'],
        ]);

        if ($wasClosed) {
            $incident->status = MotorflashLineIncident::STATUS_OPEN;
            $incident->closed_at = null;
        }

        $incident->save();

        MotorflashLineIncidentHistory::query()->create([
            'incident_id' => $incident->getKey(),
            'report_fingerprint' => $report['fingerprint'],
            'state' => $wasClosed ? MotorflashLineIncidentHistory::STATE_REOPENED : MotorflashLineIncidentHistory::STATE_ERROR,
            'company' => $row['company'],
            'phone_original' => $row['phone_original'],
            'line_name' => $row['line_name'],
            'detail' => $row['detail'],
            'occurred_at' => $row['occurred_at'],
            'line_since_at' => $row['line_since_at'],
        ]);
    }

    /** @param array<string, mixed> $report @param array<string, mixed> $row */
    private function closeExplicitOkRow(array $report, array $row): void
    {
        $incident = MotorflashLineIncident::query()
            ->where('error_type', MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED)
            ->where('phone_normalized', $row['phone_normalized'])
            ->where('status', '!=', MotorflashLineIncident::STATUS_CLOSED)
            ->lockForUpdate()
            ->first();

        if ($incident === null) {
            return;
        }

        $incident->update([
            'status' => MotorflashLineIncident::STATUS_CLOSED,
            'line_name' => $row['line_name'],
            'closed_at' => $report['report_occurred_at'],
        ]);

        MotorflashLineIncidentHistory::query()->create([
            'incident_id' => $incident->getKey(),
            'report_fingerprint' => $report['fingerprint'],
            'state' => MotorflashLineIncidentHistory::STATE_CLOSED,
            'company' => null,
            'phone_original' => null,
            'line_name' => $row['line_name'],
            'line_since_at' => $row['line_since_at'],
            'detail' => 'Estado OK confirmado en un informe completo.',
            'occurred_at' => $report['report_occurred_at'],
        ]);
    }
}
