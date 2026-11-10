<?php

namespace App\Services;

use App\Exceptions\InvalidMotorflashLineReportException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MotorflashMailboxPollingService
{
    public function __construct(
        private readonly MotorflashLineStatusReportParser $parser,
        private readonly MotorflashLineStatusReportIngestionService $ingestion,
    ) {
    }

    /** @return array{processed: int, duplicates: int, invalid: int, skipped: int} */
    public function poll(): array
    {
        $this->ensureMailboxIsAllowed();

        $stream = $this->openMailbox();
        $processed = 0;
        $duplicates = 0;
        $invalid = 0;
        $skipped = 0;

        try {
            $messageNumbers = imap_search($stream, 'ALL', SE_UID) ?: [];
            rsort($messageNumbers, SORT_NUMERIC);
            $messageNumbers = array_slice($messageNumbers, 0, max(1, (int) config('motorflash_mailbox.poll_limit', 20)));

            foreach ($messageNumbers as $messageNumber) {
                $overview = imap_fetch_overview($stream, (string) $messageNumber, FT_UID)[0] ?? null;
                $subject = $this->decodeHeader((string) ($overview->subject ?? ''));

                if (! preg_match('/ESTADO\s+LINEAS/iu', $subject)) {
                    $skipped++;
                    continue;
                }

                try {
                    $rawEmail = imap_fetchheader($stream, (string) $messageNumber, FT_UID)
                        . "\r\n"
                        . imap_body($stream, (string) $messageNumber, FT_UID | FT_PEEK);
                    $report = $this->parser->parse($rawEmail);
                    $status = $this->ingestion->ingest($report);

                    if ($status === 'duplicate') {
                        $duplicates++;
                    } else {
                        $processed++;
                    }

                    $this->moveToProcessed($stream, (int) $messageNumber);
                } catch (InvalidMotorflashLineReportException $exception) {
                    $invalid++;
                    Log::error('No se pudo validar un informe de líneas de Motorflash.', [
                        'mailbox_uid' => (int) $messageNumber,
                        'reason' => $exception->getMessage(),
                    ]);
                } catch (\Throwable $exception) {
                    $invalid++;
                    Log::error('No se pudo procesar un informe de líneas de Motorflash.', [
                        'mailbox_uid' => (int) $messageNumber,
                        'exception' => $exception::class,
                    ]);
                }
            }
        } finally {
            imap_close($stream);
        }

        return compact('processed', 'duplicates', 'invalid', 'skipped');
    }

    private function ensureMailboxIsAllowed(): void
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

        if (! function_exists('imap_open')) {
            throw new RuntimeException('La extensión IMAP de PHP no está instalada.');
        }
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

        $stream = @imap_open(
            $mailbox,
            (string) config('motorflash_mailbox.username'),
            (string) config('motorflash_mailbox.password'),
            OP_READONLY,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI'],
        );

        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el buzón de Motorflash.');
        }

        return $stream;
    }

    /** @param resource $stream */
    private function moveToProcessed($stream, int $messageNumber): void
    {
        if (! @imap_mail_move($stream, (string) $messageNumber, (string) config('motorflash_mailbox.processed_folder', 'Procesados'), CP_UID)) {
            throw new RuntimeException('No se pudo mover el mensaje de Motorflash a la carpeta de procesados.');
        }

        if (! @imap_expunge($stream)) {
            throw new RuntimeException('No se pudo confirmar el movimiento del mensaje de Motorflash.');
        }
    }

    private function decodeHeader(string $value): string
    {
        return function_exists('imap_mime_header_decode')
            ? collect(imap_mime_header_decode($value))->map(fn ($part): string => $part->text)->implode('')
            : $value;
    }
}
