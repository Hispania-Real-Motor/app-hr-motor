<?php

namespace App\Services;

use App\Exceptions\InvalidMotorflashLineReportException;
use App\Models\EnreachErrorEvent;
use Carbon\CarbonImmutable;

class MotorflashLineStatusReportParser
{
    /** @return array{fingerprint: string, original_sender: string, original_subject: string, original_message_id: ?string, report_occurred_at: CarbonImmutable, total_lines: int, problem_lines: int, rows: array<int, array<string, mixed>>} */
    public function parse(string $rawEmail): array
    {
        if (trim($rawEmail) === '') {
            throw new InvalidMotorflashLineReportException('El informe de Motorflash está vacío.');
        }

        [$headers, $body] = $this->splitEmail($rawEmail);
        $body = $this->decodeBody($headers, $body);
        $originalSender = $this->extractForwardedHeader($body, 'De');
        $originalSubject = $this->extractForwardedHeader($body, 'Asunto');
        $originalDate = $this->extractForwardedHeader($body, 'Fecha');

        if (strtolower($this->extractEmailAddress($originalSender)) !== 'message@motorflash.com') {
            throw new InvalidMotorflashLineReportException('El remitente original no corresponde a Motorflash Message.');
        }

        if ($originalSubject === null || ! preg_match('/^\[ESTADO\s+LINEAS\]\s+(\d+)\s+de\s+(\d+)\s+l[ií]neas\s+con\s+problemas$/iu', $originalSubject, $subjectParts)) {
            throw new InvalidMotorflashLineReportException('El asunto no corresponde a un informe válido de estado de líneas.');
        }

        if ($originalDate === null) {
            throw new InvalidMotorflashLineReportException('El informe no contiene la fecha original.');
        }

        try {
            $reportOccurredAt = CarbonImmutable::parse($originalDate, config('app.timezone'));
        } catch (\Throwable) {
            throw new InvalidMotorflashLineReportException('La fecha original del informe no es válida.');
        }

        $rows = $this->extractRows($body);
        $declaredProblems = (int) $subjectParts[1];
        $declaredTotal = (int) $subjectParts[2];
        $actualProblems = count(array_filter($rows, fn (array $row): bool => $row['is_error']));

        if ($declaredTotal === 0 || count($rows) !== $declaredTotal || $actualProblems !== $declaredProblems) {
            throw new InvalidMotorflashLineReportException('El informe no contiene la fotografía completa de líneas declarada en el asunto.');
        }

        $canonicalRows = $rows;
        usort($canonicalRows, fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        $canonical = [
            'sender' => strtolower($this->extractEmailAddress($originalSender)),
            'subject' => $originalSubject,
            'date' => $reportOccurredAt->toIso8601String(),
            'rows' => $canonicalRows,
        ];

        return [
            'fingerprint' => hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'original_sender' => $originalSender,
            'original_subject' => $originalSubject,
            'original_message_id' => $headers['x-forwarded-message-id'] ?? $headers['message-id'] ?? null,
            'report_occurred_at' => $reportOccurredAt,
            'total_lines' => $declaredTotal,
            'problem_lines' => $declaredProblems,
            'rows' => $rows,
        ];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function splitEmail(string $rawEmail): array
    {
        $parts = preg_split("/\r?\n\r?\n/", $rawEmail, 2);

        if (count($parts) === 1) {
            return [[], $rawEmail];
        }

        return [$this->parseHeaders($parts[0]), $parts[1]];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $headerBlock) as $line) {
            if (preg_match('/^\s+(.+)$/', $line, $continuation) && $current !== null) {
                $headers[$current] .= ' ' . trim($continuation[1]);
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $header)) {
                $current = strtolower(trim($header[1]));
                $headers[$current] = trim($header[2]);
            }
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function decodeBody(array $headers, string $body): string
    {
        return match (strtolower($headers['content-transfer-encoding'] ?? '')) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?: '', true) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function extractForwardedHeader(string $body, string $label): ?string
    {
        $pattern = sprintf('/<th[^>]*>\s*%s:\s*<\/th>\s*<td[^>]*>(.*?)<\/td>/isu', preg_quote($label, '/'));

        if (preg_match($pattern, $body, $match)) {
            return $this->cleanText($match[1]);
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function extractRows(string $body): array
    {
        $rows = [];
        $seenPhones = [];
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/isu', $body, $rowMatches);

        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/isu', $rowHtml, $cellMatches);
            $cells = array_map(fn (string $cell): string => $this->cleanText($cell), $cellMatches[1] ?? []);

            if (count($cells) < 5 || ! in_array($cells[2], ['OK', 'No encontrada'], true)) {
                continue;
            }

            $phoneNormalized = EnreachErrorEvent::normalizePhone($cells[1]);

            if ($phoneNormalized === null) {
                throw new InvalidMotorflashLineReportException('El informe contiene una línea sin teléfono válido.');
            }

            if (isset($seenPhones[$phoneNormalized])) {
                throw new InvalidMotorflashLineReportException('El informe contiene el mismo teléfono más de una vez.');
            }

            $isError = $cells[2] === 'No encontrada';

            if ($isError && ! preg_match('/no longer exists in meta|line not found|l[ií]nea no encontrada|desconectad/iu', $cells[3])) {
                throw new InvalidMotorflashLineReportException('El detalle de una línea no encontrada no corresponde al error esperado.');
            }

            try {
                $occurredAt = $this->parseLineDate($cells[4]);
            } catch (\Throwable) {
                throw new InvalidMotorflashLineReportException('El informe contiene una fecha de línea no válida.');
            }

            $seenPhones[$phoneNormalized] = true;
            $rows[] = [
                'company' => $cells[0],
                'line_name' => $cells[0],
                'phone_original' => $cells[1],
                'phone_normalized' => $phoneNormalized,
                'status' => $cells[2],
                'detail' => $cells[3],
                'since' => $cells[4],
                'line_since_at' => $occurredAt,
                'occurred_at' => $occurredAt,
                'is_error' => $isError,
            ];
        }

        return $rows;
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function parseLineDate(string $value): CarbonImmutable
    {
        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $value, config('app.timezone'));
            } catch (\Throwable) {
                // Try the next supported report format.
            }
        }

        throw new InvalidMotorflashLineReportException('Formato de fecha de línea no soportado.');
    }

    private function extractEmailAddress(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $value, $match)) {
            return trim($match[1]);
        }

        return trim($value);
    }
}
