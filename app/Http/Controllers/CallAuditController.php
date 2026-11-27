<?php

namespace App\Http\Controllers;

use App\Models\EnreachCallAudit;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

    private const SCORE_FILTERS = [
        'with_score',
        'without_score',
        'passed',
        'failed',
    ];

    public function index(Request $request): View|JsonResponse
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

        $zoneOptions = Zone::query()->orderBy('name')->pluck('name', 'id')->all();
        $requestedZone = (string) $request->query('zone_id', '');
        $zoneId = ctype_digit($requestedZone) && array_key_exists((int) $requestedZone, $zoneOptions)
            ? (int) $requestedZone
            : null;
        $zoneSalesforceIds = $zoneId !== null ? $this->salesforceIdsForZone($zoneId) : null;
        $commercialOptions = $this->commercialOptions($zoneSalesforceIds);
        $commercial = trim((string) $request->query('commercial', ''));
        $commercial = array_key_exists($commercial, $commercialOptions) ? $commercial : null;
        $dateFrom = $this->validDate($request->query('date_from'));
        $dateTo = $this->validDate($request->query('date_to'));
        $puid = trim((string) $request->query('puid', ''));
        $puid = $puid !== '' ? $puid : null;
        $requiresListeningFilter = $request->boolean('requires_listening');
        $scoreFilter = (string) $request->query('score_filter', '');
        $scoreFilter = in_array($scoreFilter, self::SCORE_FILTERS, true) ? $scoreFilter : null;

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

        if ($zoneSalesforceIds !== null) {
            if ($zoneSalesforceIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn(
                    DB::raw($this->employeeIdExpression($query->getConnection()->getDriverName())),
                    $zoneSalesforceIds->all(),
                );
            }
        }

        if ($commercial !== null) {
            $query->whereRaw(
                $this->employeeIdExpression($query->getConnection()->getDriverName()) . ' = ?',
                [$commercial],
            );
        }

        if ($requiresListeningFilter) {
            $query->whereRaw('(' . $this->requiresListeningExpression($query->getConnection()->getDriverName()) . ') IS TRUE');
        }

        if ($dateFrom !== null) {
            $query->whereDate('enreach_call_audits.created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('enreach_call_audits.created_at', '<=', $dateTo);
        }

        if ($puid !== null) {
            $query->where('enreach_call_transcriptions.call_puid', 'like', '%' . $puid . '%');
        }

        match ($scoreFilter) {
            'with_score' => $query->whereNotNull('enreach_call_audits.score'),
            'without_score' => $query->whereNull('enreach_call_audits.score'),
            'passed' => $query->whereNotNull('enreach_call_audits.score')->where('enreach_call_audits.score', '>=', 5),
            'failed' => $query->whereNotNull('enreach_call_audits.score')->where('enreach_call_audits.score', '<', 5),
            default => null,
        };

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

        $commercialUsers = $this->commercialUsersFor($audits->getCollection());

        $viewData = [
            'audits' => $audits,
            'commercialUsers' => $commercialUsers,
            'commercialOptions' => $commercialOptions,
            'zoneOptions' => $zoneOptions,
            'selectedCommercialLabel' => $commercial !== null
                ? ($commercialOptions[$commercial]['label'] ?? '')
                : '',
            'filters' => [
                'zone_id' => $zoneId,
                'commercial' => $commercial,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'puid' => $puid,
                'requires_listening' => $requiresListeningFilter,
                'score_filter' => $scoreFilter,
            ],
            'filterQuery' => array_filter([
                'zone_id' => $zoneId,
                'commercial' => $commercial,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'puid' => $puid,
                'requires_listening' => $requiresListeningFilter ? '1' : null,
                'score_filter' => $scoreFilter,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'sort' => $sort,
            'direction' => $direction,
            'explicitSort' => $explicitSort,
            'sortableColumns' => self::SORTABLE_COLUMNS,
        ];

        if ($request->boolean('ajax')) {
            return response()->json([
                'html' => view('call-audits.index', $viewData)->render(),
                'total' => $audits->total(),
            ]);
        }

        return view('call-audits.index', $viewData);
    }

    public function show(Request $request, int $audit): View
    {
        $this->authorize($request);

        $auditRecord = EnreachCallAudit::query()
            ->with('transcription')
            ->whereKey($audit)
            ->whereHas('transcription', fn ($query) => $query->where('audited_by_ai', true))
            ->firstOrFail();

        $commercialUser = $this->commercialUsersFor(collect([$auditRecord]))[$auditRecord->employeeId() ?? ''] ?? null;

        return view('call-audits.show', [
            'audit' => $auditRecord,
            'commercialName' => $commercialUser?->name ?? 'Comercial sin identificar',
        ]);
    }

    public function destroy(Request $request, int $audit): RedirectResponse
    {
        abort_unless(app_user_has_admin_permission($request->user(), 'call-audits.delete'), 403);

        $auditRecord = EnreachCallAudit::query()
            ->with('transcription')
            ->whereKey($audit)
            ->whereHas('transcription', fn ($query) => $query->where('audited_by_ai', true))
            ->firstOrFail();

        $transcription = $auditRecord->transcription;
        $auditRecord->getConnection()->transaction(function () use ($auditRecord, $transcription): void {
            $auditRecord->delete();
            $transcription?->delete();
        });

        return to_route('call-audits.index')->with('success', 'La auditoría IA y su transcripción se han eliminado definitivamente.');
    }

    private function authorize(Request $request): void
    {
        abort_unless(app_user_has_admin_permission($request->user(), self::PERMISSION), 403);
    }

    private function commercialUsersFor(Collection $audits): array
    {
        $employeeIds = $audits
            ->map(fn (EnreachCallAudit $audit): ?string => $audit->employeeId())
            ->filter()
            ->unique()
            ->values();

        if ($employeeIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('salesforce_user_id', $employeeIds->all())
            ->get(['id', 'salesforce_user_id', 'name', 'avatar_path'])
            ->groupBy('salesforce_user_id')
            ->filter(fn (Collection $users): bool => $users->count() === 1 && filled($users->first()->name))
            ->map(fn (Collection $users): User => $users->first())
            ->all();
    }

    private function salesforceIdsForZone(int $zoneId): Collection
    {
        return User::query()
            ->whereHas('assignedDealership', fn ($query) => $query->where('zone_id', $zoneId))
            ->whereNotNull('salesforce_user_id')
            ->where('salesforce_user_id', '<>', '')
            ->pluck('salesforce_user_id')
            ->map(fn (mixed $salesforceId): string => (string) $salesforceId)
            ->filter()
            ->unique()
            ->values();
    }

    private function commercialOptions(?Collection $allowedSalesforceIds = null): array
    {
        $driver = (new EnreachCallAudit)->getConnection()->getDriverName();
        $employeeIdExpression = $this->employeeIdExpression($driver);
        $employeeIds = EnreachCallAudit::query()
            ->join('enreach_call_transcriptions', 'enreach_call_transcriptions.id', '=', 'enreach_call_audits.transcription_id')
            ->where('enreach_call_transcriptions.audited_by_ai', true)
            ->whereNotNull('enreach_call_audits.audit_result')
            ->selectRaw($employeeIdExpression . ' as employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->filter(fn (mixed $employeeId): bool => is_scalar($employeeId) && trim((string) $employeeId) !== '')
            ->map(fn (mixed $employeeId): string => (string) $employeeId)
            ->values();

        if ($employeeIds->isEmpty()) {
            return [];
        }

        if ($allowedSalesforceIds !== null) {
            $employeeIds = $employeeIds->intersect($allowedSalesforceIds)->values();

            if ($employeeIds->isEmpty()) {
                return [];
            }
        }

        $users = User::query()
            ->whereNotNull('salesforce_user_id')
            ->where('salesforce_user_id', '<>', '')
            ->whereIn('salesforce_user_id', $employeeIds->all())
            ->get(['salesforce_user_id', 'name'])
            ->groupBy('salesforce_user_id')
            ->filter(fn (Collection $matches): bool => $matches->count() === 1 && filled($matches->first()->name))
            ->map(fn (Collection $matches): array => [
                'id' => (string) $matches->first()->salesforce_user_id,
                'name' => (string) $matches->first()->name,
            ])
            ->values();

        return $users
            ->mapWithKeys(fn (array $commercial): array => [
                $commercial['id'] => ['label' => $commercial['name']],
            ])
            ->sortBy('label')
            ->all();
    }

    private function validDate(mixed $value): ?string
    {
        $date = is_string($value) ? trim($value) : '';

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : null;
    }

    private function employeeIdExpression(string $driver): string
    {
        return $driver === 'pgsql'
            ? "enreach_call_audits.audit_result->'attribution'->>'employeeId'"
            : "json_extract(enreach_call_audits.audit_result, '$.attribution.employeeId')";
    }

    private function requiresListeningExpression(string $driver): string
    {
        if ($driver === 'pgsql') {
            return "CASE WHEN enreach_call_audits.audit_result->'requiresListening' IS NOT NULL THEN CASE WHEN jsonb_typeof(enreach_call_audits.audit_result->'requiresListening') = 'boolean' THEN (enreach_call_audits.audit_result->>'requiresListening')::boolean ELSE false END ELSE CASE WHEN jsonb_typeof(enreach_call_audits.audit_result->'score'->'requiereEscucha') = 'boolean' THEN (enreach_call_audits.audit_result->'score'->>'requiereEscucha')::boolean ELSE false END END";
        }

        return "CASE WHEN json_type(enreach_call_audits.audit_result, '$.requiresListening') IS NOT NULL THEN CASE WHEN json_type(enreach_call_audits.audit_result, '$.requiresListening') = 'true' THEN 1 ELSE 0 END ELSE CASE WHEN json_type(enreach_call_audits.audit_result, '$.score.requiereEscucha') = 'true' THEN 1 ELSE 0 END END = 1";
    }

    private function coverageSortExpression(string $driver): string
    {
        return $driver === 'pgsql'
            ? "NULLIF(enreach_call_audits.audit_result->'score'->>'cobertura', '')::numeric"
            : "CAST(json_extract(enreach_call_audits.audit_result, '$.score.cobertura') AS REAL)";
    }
}
