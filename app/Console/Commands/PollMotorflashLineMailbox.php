<?php

namespace App\Console\Commands;

use App\Services\MotorflashLineStatusReportIngestionService;
use App\Services\MotorflashLineStatusReportParser;
use App\Services\MotorflashMailboxPollingService;
use Illuminate\Console\Command;

class PollMotorflashLineMailbox extends Command
{
    protected $signature = 'motorflash:mailbox:poll {--file= : Procesa un .eml local sin conectarse al buzón} {--dry-run : Solo valida el informe, sin escribir en la base de datos} {--inspect : Lista UIDs y asuntos sin procesar ni mover correos}';

    protected $description = 'Lee y procesa informes de estado de líneas de Motorflash.';

    public function handle(
        MotorflashMailboxPollingService $polling,
        MotorflashLineStatusReportParser $parser,
        MotorflashLineStatusReportIngestionService $ingestion,
    ): int {
        $file = $this->option('file');

        if ($this->option('inspect')) {
            try {
                $this->table(['UID', 'Fecha', 'Asunto'], $polling->inspect());

                return self::SUCCESS;
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        if (filled($file)) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error('No se puede leer el archivo .eml indicado.');

                return self::FAILURE;
            }

            try {
                $report = $parser->parse((string) file_get_contents($file));

                if ($this->option('dry-run')) {
                    $this->info(sprintf(
                        'Informe válido. Líneas: %d. Problemas: %d. Huella: %s.',
                        $report['total_lines'],
                        $report['problem_lines'],
                        $report['fingerprint'],
                    ));

                    return self::SUCCESS;
                }

                $status = $ingestion->ingest($report);
                $this->info($status === 'duplicate' ? 'Informe ya procesado.' : 'Informe procesado correctamente.');

                return self::SUCCESS;
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $result = $polling->poll();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Informes nuevos: %d. Duplicados: %d. Inválidos: %d. Omitidos: %d.',
            $result['processed'],
            $result['duplicates'],
            $result['invalid'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
