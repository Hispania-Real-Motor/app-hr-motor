<?php

namespace App\Services;

use RuntimeException;

class MotorflashMailboxImapClient
{
    /** @return resource */
    public function open(string $mailbox, string $username, string $password, bool $readOnly = false)
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('La extensión IMAP de PHP no está instalada.');
        }

        $stream = @imap_open(
            $mailbox,
            $username,
            $password,
            $readOnly ? OP_READONLY : 0,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI'],
        );

        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el buzón de Motorflash.');
        }

        return $stream;
    }

    /** @param resource $stream @return array<int, int> */
    public function search($stream, string $criteria): array
    {
        $uids = @imap_search($stream, $criteria, SE_UID);

        if ($uids === false) {
            throw new RuntimeException('No se pudo buscar mensajes en el buzón de Motorflash.');
        }

        return array_map('intval', $uids);
    }

    /** @param resource $stream @return object|null */
    public function overview($stream, int $uid): ?object
    {
        return imap_fetch_overview($stream, (string) $uid, FT_UID)[0] ?? null;
    }

    /** @param resource $stream */
    public function raw($stream, int $uid): string
    {
        return imap_fetchheader($stream, (string) $uid, FT_UID)
            . "\r\n"
            . imap_body($stream, (string) $uid, FT_UID | FT_PEEK);
    }

    /** @param resource $stream */
    public function moveToProcessed($stream, int $uid, string $folder): void
    {
        if (! @imap_mail_move($stream, (string) $uid, $folder, CP_UID) || ! @imap_expunge($stream)) {
            throw new RuntimeException('No se pudo mover el mensaje de Motorflash a la carpeta de procesados.');
        }
    }

    /** @param resource $stream */
    public function close($stream): void
    {
        imap_close($stream);
    }
}
