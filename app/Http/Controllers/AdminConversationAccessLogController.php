<?php

namespace App\Http\Controllers;

use App\Models\CompanyChatConversationAccessAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminConversationAccessLogController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        $dateFrom = $this->sanitizeDate($request->query('date_from'));
        $dateTo = $this->sanitizeDate($request->query('date_to'));
        [$dateFrom, $dateTo] = $this->normalizeDateRange($dateFrom, $dateTo);
        $userId = $this->sanitizeUserId($request->query('user'));

        if (! Schema::hasTable('company_chat_conversation_access_audits')) {
            return response()->streamDownload(function (): void {
                $output = fopen('php://output', 'w');

                fputcsv($output, [
                    'fecha_hora',
                    'administrador',
                    'email_administrador',
                    'accion',
                    'conversation_id',
                    'conversation_type',
                    'usuarios_afectados',
                    'motivo',
                    'ip',
                    'user_agent',
                    'resultado',
                ], ';');

                fclose($output);
            }, 'accesos-conversacion-pendiente-migracion.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $logs = $this->filteredLogsQuery($dateFrom, $dateTo, $userId)
            ->orderByDesc('accessed_at')
            ->get();

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "accesos-conversacion-{$timestamp}.csv";

        return response()->streamDownload(function () use ($logs): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'fecha_hora',
                'administrador',
                'email_administrador',
                'accion',
                'conversation_id',
                'conversation_type',
                'usuarios_afectados',
                'motivo',
                'ip',
                'user_agent',
                'resultado',
            ], ';');

            foreach ($logs as $log) {
                fputcsv($output, [
                    $log->accessed_at?->format('Y-m-d H:i:s'),
                    $log->adminUser?->name ?? 'Usuario eliminado',
                    $log->admin_email,
                    $log->action,
                    $log->company_chat_conversation_id,
                    $log->conversation_type,
                    $this->formatAffectedUsers($log->conversation?->retention_hold_target_label ? [$log->conversation->retention_hold_target_label] : ($log->affected_user_ids ?? [])),
                    $log->reason,
                    $log->ip_address,
                    $log->user_agent,
                    $log->result,
                ], ';');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function sanitizeDate(?string $date): ?string
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $date;
    }

    private function normalizeDateRange(?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            return [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    private function sanitizeUserId(mixed $userId): ?int
    {
        if (! is_numeric($userId)) {
            return null;
        }

        $userId = (int) $userId;

        return $userId > 0 ? $userId : null;
    }

    private function availableUsers(): Collection
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function filteredLogsQuery(?string $dateFrom, ?string $dateTo, ?int $userId): Builder
    {
        return CompanyChatConversationAccessAudit::query()
            ->with(['adminUser', 'conversation.userOne', 'conversation.userTwo'])
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('accessed_at', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('accessed_at', '<=', $dateTo))
            ->when($userId, fn (Builder $query) => $query->where('admin_user_id', $userId));
    }

    private function formatAffectedUsers(array|string|null $users): string
    {
        if (is_string($users)) {
            return $users;
        }

        return collect($users ?? [])
            ->map(static fn (mixed $user): string => is_array($user) ? (string) ($user['name'] ?? 'Usuario') : (string) $user)
            ->implode(' | ');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(app_user_has_admin_permission($request->user(), 'conversation-access.manage'), 403);
    }
}
