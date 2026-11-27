<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnreachCallAudit extends Model
{
    protected $table = 'enreach_call_audits';

    protected $connection = 'enreach_ai';

    protected $fillable = [
        'transcription_id',
        'audit_result',
        'score',
        'rules_version',
        'evaluator_version',
    ];

    protected function casts(): array
    {
        return [
            'audit_result' => 'array',
            'score' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function transcription()
    {
        return $this->belongsTo(EnreachCallTranscription::class, 'transcription_id');
    }

    public function coverage(): mixed
    {
        return $this->scoreData()['cobertura'] ?? null;
    }

    public function auditResultData(): array
    {
        $result = $this->audit_result;

        if (is_string($result)) {
            $result = json_decode($result, true);
        }

        return is_array($result) ? $result : [];
    }

    public function scoreData(): array
    {
        $score = $this->auditResultData()['score'] ?? [];

        return is_array($score) ? $score : [];
    }

    public function evaluationData(): array
    {
        $evaluation = $this->auditResultData()['evaluation'] ?? [];

        return is_array($evaluation) ? $evaluation : [];
    }

    public function summary(): mixed
    {
        return $this->evaluationData()['resumen'] ?? null;
    }

    public function status(): mixed
    {
        return self::readableStatus($this->scoreData()['estado'] ?? null);
    }

    public function requiresListening(): bool
    {
        $result = $this->auditResultData();

        if (array_key_exists('requiresListening', $result)) {
            return is_bool($result['requiresListening']) && $result['requiresListening'];
        }

        $score = $result['score'] ?? null;

        return is_array($score)
            && array_key_exists('requiereEscucha', $score)
            && is_bool($score['requiereEscucha'])
            && $score['requiereEscucha'];
    }

    public function criticalGlobal(): bool
    {
        $value = $this->scoreData()['criticoGlobal'] ?? null;

        return is_bool($value) && $value;
    }

    public function listeningAlerts(): array
    {
        $result = $this->auditResultData();
        $sources = array_merge(
            self::alertItems($result['alerts'] ?? null),
            self::alertItems($this->scoreData()['avisos'] ?? null),
        );
        $alerts = [];
        $seen = [];

        foreach ($sources as $source) {
            $alert = self::normalizeListeningAlert($source);

            if ($alert === null) {
                continue;
            }

            $key = self::alertKey($alert);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $alerts[] = $alert;
        }

        return $alerts;
    }

    public function alerts(): array
    {
        $alerts = $this->scoreData()['alertas'] ?? [];

        return is_array($alerts) ? array_values($alerts) : (is_scalar($alerts) ? [(string) $alerts] : []);
    }

    /**
     * Returns only relevant alerts that are not already shown in the listening block.
     * This is presentation data; the stored JSON is left untouched.
     */
    public function additionalAlerts(): array
    {
        $listeningAlerts = $this->listeningAlerts();
        $additional = [];

        foreach ($this->alerts() as $source) {
            $alert = self::normalizeListeningAlert($source);

            if ($alert === null) {
                continue;
            }

            $isDuplicate = false;

            foreach ($listeningAlerts as $listeningAlert) {
                if (self::alertsRepresentSameIncident($alert, $listeningAlert)) {
                    $isDuplicate = true;
                    break;
                }
            }

            if ($isDuplicate) {
                continue;
            }

            $additional[] = $alert;
        }

        return $additional;
    }

    public function sourceQuality(): mixed
    {
        return $this->auditResultData()['sourceQuality'] ?? null;
    }

    public function attribution(): mixed
    {
        return $this->auditResultData()['attribution'] ?? null;
    }

    public function employeeId(): ?string
    {
        $attribution = $this->attribution();
        $employeeId = is_array($attribution) ? ($attribution['employeeId'] ?? null) : null;

        return is_scalar($employeeId) && trim((string) $employeeId) !== ''
            ? (string) $employeeId
            : null;
    }

    public function criteriaForDisplay(): array
    {
        $scoreCriteria = collect($this->scoreData()['criterios'] ?? [])
            ->filter(fn (mixed $criterion): bool => is_array($criterion) && isset($criterion['id']))
            ->keyBy('id');

        return collect($this->evaluationData()['criterios'] ?? [])
            ->filter(fn (mixed $criterion): bool => is_array($criterion) && isset($criterion['id']))
            ->map(function (array $criterion) use ($scoreCriteria): array {
                $metadata = $scoreCriteria->get($criterion['id'], []);
                $evaluationStatus = $criterion['valoracion'] ?? null;

                return [
                    'id' => (string) $criterion['id'],
                    'name' => $metadata['nombre'] ?? $criterion['nombre'] ?? 'Criterio sin nombre',
                    'weight' => $metadata['peso'] ?? $criterion['peso'] ?? null,
                    'value' => self::readableEvaluationStatus($evaluationStatus),
                    'is_not_applicable' => $evaluationStatus === 'N/A',
                    'justification' => $criterion['justificacion'] ?? null,
                    'evidence' => self::evidenceForDisplay($criterion),
                    'context' => self::contextEvidenceForDisplay($criterion),
                ];
            })
            ->values()
            ->all();
    }

    public static function readableEvaluationStatus(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return [
            'N/A' => 'No aplicable',
            'N/E' => 'No evaluable',
            'Cumple' => 'Cumple',
            'Cumple parcialmente' => 'Cumple parcialmente',
            'No cumple' => 'No cumple',
        ][$value] ?? $value;
    }

    public static function readableStatus(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return [
            'PENDIENTE - EVIDENCIA INSUFICIENTE' => 'Pendiente: evidencia insuficiente',
            'PILOTO_PROVISIONAL' => 'Piloto provisional',
        ][$value] ?? $value;
    }

    private static function evidenceForDisplay(array $criterion): array
    {
        $structured = $criterion['evidencias'] ?? null;

        if (is_array($structured)) {
            $validStructured = array_values(array_filter(
                $structured,
                static fn (mixed $item): bool => is_array($item)
                    && is_string($item['texto'] ?? null)
                    && trim($item['texto']) !== '',
            ));

            if ($validStructured !== []) {
                return self::deduplicateEvidence($validStructured);
            }
        }

        $fallback = $criterion['evidencia'] ?? null;

        return is_string($fallback) && trim($fallback) !== ''
            ? [['text' => $fallback]]
            : [];
    }

    private static function contextEvidenceForDisplay(array $criterion): array
    {
        $context = $criterion['contextoEvidencias'] ?? null;

        if (! is_array($context)) {
            return [];
        }

        $validContext = array_values(array_filter(
            $context,
            static fn (mixed $item): bool => is_array($item)
                && is_string($item['texto'] ?? null)
                && trim($item['texto']) !== '',
        ));

        return self::deduplicateEvidence($validContext);
    }

    private static function deduplicateEvidence(array $items): array
    {
        $evidence = [];
        $seen = [];

        foreach ($items as $item) {
            $text = $item['texto'] ?? null;
            $fragmentId = $item['id'] ?? null;
            $actor = $item['actor'] ?? null;
            $from = $item['desde'] ?? null;
            $until = $item['hasta'] ?? null;
            $key = is_scalar($fragmentId) && (string) $fragmentId !== ''
                ? 'id:' . (string) $fragmentId
                : 'value:' . hash('sha256', json_encode([$text, $actor, $from, $until], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (! is_string($text) || trim($text) === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $evidence[] = [
                'text' => $text,
                'id' => $fragmentId,
                'actor' => $actor,
                'certainty' => $item['certeza'] ?? null,
                'from' => $from,
                'until' => $until,
            ];
        }

        return $evidence;
    }

    private static function alertItems(mixed $value): array
    {
        if (is_scalar($value)) {
            return [(string) $value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    private static function normalizeListeningAlert(mixed $source): ?array
    {
        if (is_scalar($source)) {
            $reason = trim((string) $source);

            return $reason === '' ? null : [
                'code' => self::legacyAlertCode($reason),
                'criterion' => null,
                'severity' => null,
                'reason' => self::legacyAlertReason($reason),
                'evidences' => [],
            ];
        }

        if (! is_array($source)) {
            return null;
        }

        $read = static function (array $item, array $keys): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $item) && is_scalar($item[$key])) {
                    $value = trim((string) $item[$key]);

                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            return null;
        };

        $evidenceValue = $source['evidencias'] ?? $source['evidence'] ?? null;
        $evidenceItems = self::alertItems($evidenceValue);
        $evidences = [];

        foreach ($evidenceItems as $item) {
            if (is_string($item) && trim($item) !== '') {
                $evidences[] = ['text' => $item, 'id' => null, 'actor' => null, 'from' => null, 'until' => null];
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $text = $read($item, ['texto', 'text', 'cita', 'quote']);

            if ($text === null) {
                continue;
            }

            $evidences[] = [
                'text' => $text,
                'id' => $read($item, ['id', 'fragmentId', 'fragment_id']),
                'actor' => $read($item, ['actor', 'interlocutor', 'speaker']),
                'from' => $read($item, ['desde', 'from', 'inicio', 'start']),
                'until' => $read($item, ['hasta', 'until', 'fin', 'end']),
            ];
        }

        $alert = [
            'code' => $read($source, ['codigo', 'code', 'codigoIncidencia', 'incidentCode']),
            'criterion' => $read($source, ['criterio', 'criterion', 'criterioId', 'criterionId']),
            'severity' => $read($source, ['severidad', 'severity']),
            'reason' => $read($source, ['motivo', 'reason', 'descripcion', 'description']),
            'evidences' => self::deduplicateAlertEvidence($evidences),
        ];

        return $alert['code'] !== null || $alert['criterion'] !== null || $alert['severity'] !== null
            || $alert['reason'] !== null || $alert['evidences'] !== []
            ? $alert
            : null;
    }

    private static function deduplicateAlertEvidence(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = $item['id'] !== null
                ? 'id:' . $item['id']
                : hash('sha256', json_encode([$item['text'], $item['actor'], $item['from'], $item['until']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    private static function alertKey(array $alert): string
    {
        $evidenceKeys = self::alertEvidenceKeys($alert['evidences'] ?? []);
        $identity = [
            $alert['code'],
            $alert['criterion'],
            $alert['severity'],
            $evidenceKeys,
        ];

        if ($evidenceKeys === []) {
            $identity[] = self::normalizedAlertPart($alert['reason'] ?? null);
        }

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function alertsRepresentSameIncident(array $left, array $right): bool
    {
        $leftCode = self::normalizedAlertPart($left['code'] ?? null);
        $rightCode = self::normalizedAlertPart($right['code'] ?? null);
        $leftCriterion = self::normalizedAlertPart($left['criterion'] ?? null);
        $rightCriterion = self::normalizedAlertPart($right['criterion'] ?? null);

        $leftReason = self::normalizedAlertPart($left['reason'] ?? null);

        // Legacy alerts are often just a sentence. Compare them conservatively
        // with the structured reason or an exact evidence quote.
        if ($leftCode === null && $leftCriterion === null && ($left['evidences'] ?? []) === [] && $leftReason !== null) {
            if ($leftReason === self::normalizedAlertPart($right['reason'] ?? null)) {
                return true;
            }

            foreach ($right['evidences'] ?? [] as $evidence) {
                if ($leftReason === self::normalizedAlertPart($evidence['text'] ?? null)) {
                    return true;
                }
            }

            return false;
        }

        if ($leftCode !== null && $rightCode !== null && $leftCode !== $rightCode) {
            return false;
        }

        if ($leftCriterion !== null && $rightCriterion !== null && $leftCriterion !== $rightCriterion) {
            return false;
        }

        $leftEvidence = self::alertEvidenceKeys($left['evidences'] ?? []);
        $rightEvidence = self::alertEvidenceKeys($right['evidences'] ?? []);

        // A legacy text alert can be the compact representation of a
        // structured incident and therefore does not repeat its evidence.
        if ($leftEvidence === [] && $leftCode !== null && $leftReason !== null) {
            return $rightCode === $leftCode
                && $leftReason === self::normalizedAlertPart($right['reason'] ?? null);
        }

        if ($leftEvidence !== [] || $rightEvidence !== []) {
            return $leftEvidence !== [] && $leftEvidence === $rightEvidence;
        }

        $rightReason = self::normalizedAlertPart($right['reason'] ?? null);

        return $leftReason !== null && $leftReason === $rightReason;
    }

    private static function legacyAlertCode(string $value): ?string
    {
        if (preg_match('/^\s*(?:cr[ií]tico|aviso)\s*:\s*([a-z0-9][a-z0-9_-]*)\s+(.+)$/iu', $value, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private static function legacyAlertReason(string $value): string
    {
        if (preg_match('/^\s*(?:cr[ií]tico|aviso)\s*:\s*[a-z0-9][a-z0-9_-]*\s+(.+)$/iu', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return $value;
    }

    private static function alertEvidenceKeys(array $evidences): array
    {
        $keys = [];

        foreach ($evidences as $evidence) {
            if (! is_array($evidence)) {
                continue;
            }

            $keys[] = $evidence['id'] !== null && $evidence['id'] !== ''
                ? 'id:' . self::normalizedAlertPart($evidence['id'])
                : 'value:' . hash('sha256', json_encode([
                    self::normalizedAlertPart($evidence['text'] ?? null),
                    self::normalizedAlertPart($evidence['actor'] ?? null),
                    self::normalizedAlertPart($evidence['from'] ?? null),
                    self::normalizedAlertPart($evidence['until'] ?? null),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        sort($keys);

        return $keys;
    }

    private static function normalizedAlertPart(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower((string) preg_replace('/\s+/u', ' ', $value), 'UTF-8')
            : strtolower((string) preg_replace('/\s+/', ' ', $value));
    }

}
