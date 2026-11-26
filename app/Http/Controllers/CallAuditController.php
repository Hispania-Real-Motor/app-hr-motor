<?php

namespace App\Http\Controllers;

use App\Models\EnreachCallAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CallAuditController extends Controller
{
    private const PERMISSION = 'call-audits.view';

    private const SORTABLE_COLUMNS = [
        'audit_date' => 'Fecha de auditoría',
        'call_puid' => 'PUID',
        'score' => 'Nota provisional',
        'coverage' => 'Cobertura',
    ];

    public function index(Request $request): View
    {
        $this->authorize($request);

        $requestedSort = (string) $request->query('sort', '');
        $requestedDirection = strtolower((string) $request->query('direction', ''));
        $sort = $requestedSort ?: 'audit_date';
        $direction = $requestedDirection ?: 'desc';
        $explicitSort = array_key_exists($requestedSort, self::SORTABLE_COLUMNS)
            && in_array($requestedDirection, ['asc', 'desc'], true);

        if (! array_key_exists($sort, self::SORTABLE_COLUMNS)) {
            $sort = 'audit_date';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $query = EnreachCallAudit::query()
            ->join('enreach_call_transcriptions', 'enreach_call_transcriptions.id', '=', 'enreach_call_audits.transcription_id')
            ->where('enreach_call_transcriptions.audited_by_ai', true)
            ->select([
                'enreach_call_audits.id',
                'enreach_call_audits.audit_result',
                'enreach_call_audits.score',
                'enreach_call_audits.created_at',
                'enreach_call_transcriptions.call_puid',
            ]);

        $sortExpression = match ($sort) {
            'call_puid' => 'enreach_call_transcriptions.call_puid',
            'score' => 'enreach_call_audits.score',
            'coverage' => $this->coverageSortExpression($query->getConnection()->getDriverName()),
            default => 'enreach_call_audits.created_at',
        };

        // Keep NULL values at the end in both directions. Every expression is
        // selected from the closed whitelist above; no request value reaches SQL.
        $query
            ->orderByRaw("CASE WHEN {$sortExpression} IS NULL THEN 1 ELSE 0 END ASC")
            ->orderByRaw("{$sortExpression} " . strtoupper($direction));

        $audits = $query
            ->paginate(15)
            ->withQueryString();

        return view('call-audits.index', [
            'audits' => $audits,
            'sort' => $sort,
            'direction' => $direction,
            'explicitSort' => $explicitSort,
            'sortableColumns' => self::SORTABLE_COLUMNS,
        ]);
    }

    public function show(Request $request, int $audit): View
    {
        $this->authorize($request);

        $auditRecord = EnreachCallAudit::query()
            ->with('transcription')
            ->whereKey($audit)
            ->whereHas('transcription', fn ($query) => $query->where('audited_by_ai', true))
            ->firstOrFail();

        return view('call-audits.show', ['audit' => $auditRecord]);
    }

    private function authorize(Request $request): void
    {
        abort_unless(app_user_has_admin_permission($request->user(), self::PERMISSION), 403);
    }

    private function coverageSortExpression(string $driver): string
    {
        return $driver === 'pgsql'
            ? "NULLIF(enreach_call_audits.audit_result->'score'->>'cobertura', '')::numeric"
            : "CAST(json_extract(enreach_call_audits.audit_result, '$.score.cobertura') AS REAL)";
    }
}
