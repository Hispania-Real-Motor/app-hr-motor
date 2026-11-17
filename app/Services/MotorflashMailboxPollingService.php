<?php

namespace App\Services;

use App\Exceptions\InvalidMotorflashLineReportException;
use App\Models\MotorflashMailboxCursor;
use App\Models\MotorflashLineReportReceipt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MotorflashMailboxPollingService
{
    public function __construct(
        private readonly MotorflashLineStatusReportParser $parser,
        private readonly MotorflashLineStatusReportIngestionService $ingestion,
        private readonly MotorflashMailboxImapClient $imap,
    ) {
    }

    /** @return array{processed: int, duplicates: int, invalid: int, skipped: int} */
    public function poll(): array
    {
        $this->ensureMailboxIsAllowed(requireStartUid: false);

        $stream = $this->openMailbox();
        $processed = 0;
        $duplicates = 0;
        $invalid = 0;
        $skipped = 0;

        try {
            $cursor = MotorflashMailboxCursor::query()
                ->where('mailbox_key', $this->cursorKey())
                ->first();
            $lastUid = $cursor?->last_uid ?? ($this->configuredStartUid() - 1);
            $messageNumbers = $this->reportUids($stream, (int) $lastUid + 1);

            if ($cursor === null) {
                $cursor = MotorflashMailboxCursor::query()->create([
                    'mailbox_key' => $this->cursorKey(),
                    'last_uid' => $this->configuredStartUid() - 1,
                ]);
            }
            $pending = [];
            $handled = [];
            $latestProcessedAt = MotorflashLineReportReceipt::query()->max('report_occurred_at');

            foreach ($messageNumbers as $messageNumber) {
                $overview = $this->imap->overview($stream, $messageNumber);
                $subject = $this->decodeHeader((string) ($overview->subject ?? ''));

                if (! $this->isReportSubject($subject)) {
                    continue;
                }

                try {
                    $pending[] = ['uid' => $messageNumber, 'report' => $this->parser->parse($this->imap->raw($stream, $messageNumber))];
                } catch (InvalidMotorflashLineReportException $exception) {
                    $invalid++;
                    Log::error('No se pudo validar un informe de líneas de Motorflash.', [
                        'mailbox_uid' => $messageNumber,
                        'reason' => $exception->getMessage(),
                    ]);
                    $handled[$messageNumber] = true;
                } catch (\Throwable $exception) {
                    $invalid++;
                    Log::error('No se pudo procesar un informe de líneas de Motorflash.', [
                        'mailbox_uid' => $messageNumber,
                        'exception' => $exception::class,
                    ]);
                    $handled[$messageNumber] = true;
                }
            }

            $latestProcessedAt = $latestProcessedAt !== null
                ? \Carbon\CarbonImmutable::parse($latestProcessedAt)
                : null;

            foreach ($pending as &$item) {
                $item['stale'] = $latestProcessedAt !== null
                    && $item['report']['report_occurred_at'] <= $latestProcessedAt
                    && ! MotorflashLineReportReceipt::query()
                        ->where('fingerprint', $item['report']['fingerprint'])
                        ->exists();
            }
            unset($item);

            $this->advanceCursor($cursor, $messageNumbers, $handled);

            usort($pending, fn (array $left, array $right): int => $left['report']['report_occurred_at'] <=> $right['report']['report_occurred_at'] ?: $left['uid'] <=> $right['uid']);

            foreach ($pending as $item) {
                try {
                    if ($item['stale']) {
                        $this->imap->moveToProcessed($stream, $item['uid'], (string) config('motorflash_mailbox.processed_folder', 'Procesados'));
                        $skipped++;
                        $handled[$item['uid']] = true;
                        $this->advanceCursor($cursor, $messageNumbers, $handled);
                        continue;
                    }

                    $status = $this->ingestion->ingest($item['report']);
                    $status === 'duplicate' ? $duplicates++ : $processed++;
                    $this->imap->moveToProcessed($stream, $item['uid'], (string) config('motorflash_mailbox.processed_folder', 'Procesados'));
                    $handled[$item['uid']] = true;
                    $this->advanceCursor($cursor, $messageNumbers, $handled);
                } catch (\Throwable $exception) {
                    $invalid++;
                    Log::error('No se pudo procesar un informe de líneas de Motorflash.', [
                        'mailbox_uid' => $item['uid'],
                        'exception' => $exception::class,
                    ]);
                }
            }
        } finally {
            $this->imap->close($stream);
        }

        return compact('processed', 'duplicates', 'invalid', 'skipped');
    }

    /** @return array<int, array{uid: int, subject: string, date: string}> */
    public function inspect(): array
    {
        $this->ensureMailboxIsAllowed(requireStartUid: false);
        $stream = $this->openMailbox();
        $messages = [];

        try {
            foreach ($this->imap->search($stream, 'ALL') as $uid) {
                $overview = $this->imap->overview($stream, $uid);
                $messages[] = [
                    'uid' => (int) $uid,
                    'subject' => $this->decodeHeader((string) ($overview->subject ?? '')),
                    'date' => (string) ($overview->date ?? ''),
                ];
            }
        } finally {
            $this->imap->close($stream);
        }

        usort($messages, fn (array $left, array $right): int => $left['uid'] <=> $right['uid']);

        return $messages;
    }

    private function ensureMailboxIsAllowed(bool $requireStartUid = true): void
    {
        if (! config('motorflash_mailbox.enabled')) {
            throw new RuntimeException('La lectura del buzón de Motorflash está desactivada.');
        }

        if (! app()->environment('production') && ! config('motorflash_mailbox.allow_non_production')) {
            throw new RuntimeException('La lectura del buzón real de Motorflash está limitada a producción.');
        }

        foreach (['host', 'username', 'password'] as $key) {
            if (blank(config("motorflash_mailbox.{$key}"))) {
                throw new RuntimeException("Falta configurar motorflash_mailbox.{$key}.");
            }
        }

        if ($requireStartUid) {
            $this->configuredStartUid();
        }

    }

    private function configuredStartUid(): int
    {
        $value = config('motorflash_mailbox.start_uid');

        if (! is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('Falta configurar un MOTORFLASH_MAILBOX_START_UID válido.');
        }

        return (int) $value;
    }

    private function isReportSubject(string $subject): bool
    {
        return preg_match('/ESTADO\s+LINEAS/iu', $subject) === 1
            || preg_match('/^Todo\s+correcto\s*-/iu', $subject) === 1;
    }

    /** @return resource */
    private function openMailbox()
    {
        $mailbox = sprintf(
            '{%s:%d/imap/%s%s}%s',
            config('motorflash_mailbox.host'),
            config('motorflash_mailbox.port'),
            config('motorflash_mailbox.encryption', 'ssl'),
            config('motorflash_mailbox.validate_cert', true) ? '' : '/novalidate-cert',
            config('motorflash_mailbox.folder', 'INBOX'),
        );

        return $this->imap->open($mailbox, (string) config('motorflash_mailbox.username'), (string) config('motorflash_mailbox.password'));
    }

    /** @param resource $stream @return array<int, int> */
    private function reportUids($stream, int $fromUid): array
    {
        $criteria = 'OR SUBJECT "[ESTADO LINEAS]" SUBJECT "Todo correcto"';
        $uids = $this->imap->search($stream, $criteria);
        sort($uids, SORT_NUMERIC);
        return array_values(array_unique(array_filter($uids, fn (int $uid): bool => $uid >= $fromUid)));
    }

    private function cursorKey(): string
    {
        return (string) config('motorflash_mailbox.folder', 'INBOX');
    }

    /** @param array<int, int> $candidateUids @param array<int, bool> $handled */
    private function advanceCursor(MotorflashMailboxCursor $cursor, array $candidateUids, array $handled): void
    {
        $newLastUid = (int) $cursor->last_uid;

        foreach ($candidateUids as $uid) {
            if ($uid <= $newLastUid) {
                continue;
            }

            if (! ($handled[$uid] ?? false)) {
                break;
            }

            $newLastUid = $uid;
        }

        if ($newLastUid !== (int) $cursor->last_uid) {
            $cursor->update(['last_uid' => $newLastUid]);
        }
    }

    private function decodeHeader(string $value): string
    {
        return function_exists('imap_mime_header_decode')
            ? collect(imap_mime_header_decode($value))->map(fn ($part): string => $part->text)->implode('')
            : $value;
    }
}
