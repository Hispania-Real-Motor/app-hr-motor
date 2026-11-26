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

    public function alerts(): array
    {
        $alerts = $this->scoreData()['alertas'] ?? [];

        return is_array($alerts) ? array_values($alerts) : (is_scalar($alerts) ? [(string) $alerts] : []);
    }

    public function sourceQuality(): mixed
    {
        return $this->auditResultData()['sourceQuality'] ?? null;
    }

    public function attribution(): mixed
    {
        return $this->auditResultData()['attribution'] ?? null;
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

                return [
                    'id' => (string) $criterion['id'],
                    'name' => $metadata['nombre'] ?? $criterion['nombre'] ?? 'Criterio sin nombre',
                    'weight' => $metadata['peso'] ?? $criterion['peso'] ?? null,
                    'value' => self::readableEvaluationStatus($criterion['valoracion'] ?? null),
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

}
