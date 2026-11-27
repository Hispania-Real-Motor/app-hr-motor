<?php

namespace App\Http\Controllers;

use App\Models\EnreachCallAudit;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class CallAuditController extends Controller
{
    private const PERMISSION = 'call-audits.view';

    private const RECORDING_URL_PREFIX = 'https://manager.masvoz.es/api/rs/call/detail/download/iframe/uniqueid/';

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

    public function recording(Request $request, int $audit): HttpResponse|StreamedResponse
    {
        $this->authorize($request);

        $auditRecord = EnreachCallAudit::query()
            ->with('transcription')
            ->whereKey($audit)
            ->whereHas('transcription', fn ($query) => $query->where('audited_by_ai', true))
            ->firstOrFail();

        $puid = trim((string) ($auditRecord->transcription?->call_puid ?? ''));

        if ($puid === '') {
            return $this->recordingError($request, 'La grabación no está disponible porque la llamada no tiene PUID.', 404);
        }

        if (preg_match('~[\x00-\x20\x7F\\/]~', $puid) === 1 || strlen($puid) > 512) {
            return $this->recordingError($request, 'La grabación no está disponible para este identificador.', 404);
        }

        $username = config('services.enreach_recordings.username');
        $password = config('services.enreach_recordings.password');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return $this->recordingError($request, 'La reproducción de grabaciones no está configurada en el servidor.', 503);
        }

        $range = $request->header('Range');
        $requestHeaders = [];

        if ($range !== null) {
            if (! is_string($range) || preg_match('/^bytes=\d*-\d*$/', trim($range)) !== 1) {
                return $this->recordingError($request, 'El rango de reproducción solicitado no es válido.', 416, ['Content-Range' => 'bytes */*']);
            }

            $requestHeaders['Range'] = trim($range);
        }

        $url = self::RECORDING_URL_PREFIX . rawurlencode($puid) . '.mp3';

        try {
            $upstream = Http::withBasicAuth($username, $password)
                ->withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                ])
                ->timeout(60)
                ->connectTimeout(10)
                ->withHeaders($requestHeaders)
                ->get($url);
        } catch (ConnectionException) {
            Log::warning('Enreach recording connection failed.', ['audit_id' => $audit]);

            return $this->recordingError($request, 'No se ha podido conectar con el proveedor de grabaciones.', 502);
        }

        Log::info('Enreach recording response received.', [
            'audit_id' => $audit,
            'status' => $upstream->status(),
            'content_type' => $upstream->header('Content-Type'),
            'content_length' => $upstream->header('Content-Length'),
            'content_range' => $upstream->header('Content-Range'),
            'requested_range' => $range,
        ]);

        if ($upstream->status() === 401 || $upstream->status() === 403) {
            return $this->recordingError($request, 'El proveedor no ha autorizado el acceso a esta grabación.', 502);
        }

        if ($upstream->status() === 404) {
            return $this->recordingError($request, 'El proveedor no tiene disponible la grabación de esta llamada.', 404);
        }

        if ($upstream->status() === 416) {
            return $this->recordingError($request, 'El proveedor no admite el tramo solicitado de la grabación.', 416, [
                'Content-Range' => $upstream->header('Content-Range') ?: 'bytes */*',
            ]);
        }

        if ($upstream->status() < 200 || $upstream->status() > 299) {
            return $this->recordingError($request, 'No se ha podido obtener la grabación en este momento.', 502);
        }

        $resource = $upstream->resource();

        if (! is_resource($resource)) {
            return $this->recordingError($request, 'No se ha podido reproducir la grabación recibida.', 502);
        }

        $initialChunk = fread($resource, 8192);

        if ($initialChunk === false || $initialChunk === '') {
            fclose($resource);
            Log::warning('Enreach recording response contained no audio bytes.', ['audit_id' => $audit]);

            return $this->recordingError($request, 'El proveedor no ha devuelto datos de audio reproducibles.', 502);
        }

        Log::info('Enreach recording audio bytes received.', [
            'audit_id' => $audit,
            'bytes' => strlen($initialChunk),
        ]);

        $responseHeaders = [
            'Content-Type' => $upstream->header('Content-Type') ?: 'audio/mpeg',
            'Cache-Control' => 'private, no-store',
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ];

        foreach (['Content-Length', 'Content-Range'] as $header) {
            $value = $upstream->header($header);

            if (is_string($value) && $value !== '') {
                $responseHeaders[$header] = $value;
            }
        }

        if ($request->boolean('download')) {
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $puid) ?: 'grabacion';
            $responseHeaders['Content-Disposition'] = 'attachment; filename="llamada-' . $filename . '.mp3"';
        }

        return response()->stream(function () use ($resource, $initialChunk): void {
            try {
                echo $initialChunk;
                flush();

                while (! feof($resource)) {
                    $chunk = fread($resource, 8192);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($resource);
            }
        }, $upstream->status(), $responseHeaders);
    }

    private function recordingError(Request $request, string $message, int $status, array $headers = []): HttpResponse
    {
        $responseHeaders = array_merge([
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $headers);

        if ($request->boolean('diagnostic') || $request->expectsJson()) {
            return response()->json(['message' => $message], $status, $responseHeaders);
        }

        return response($message, $status, $responseHeaders);
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
