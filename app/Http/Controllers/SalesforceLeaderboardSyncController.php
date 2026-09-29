<?php

namespace App\Http\Controllers;

use App\Services\LeaderboardSyncService;
use Throwable;

class SalesforceLeaderboardSyncController extends Controller
{
    public function __invoke(
        LeaderboardSyncService $service,
    )
    {
        abort_unless(app_user_has_admin_permission(auth()->user(), 'backoffice.rankings.manage'), 403);

        $redirect = redirect()->back();

        if (! $service->hasSalesforceConnection()) {
            return $redirect
                ->with('error', 'Salesforce todavia no esta conectado. La app sigue operativa, pero el leaderboard no puede sincronizarse hasta completar la autorizacion.');
        }

        try {
            $service->sync();
        } catch (Throwable $exception) {
            report($exception);

            return $redirect
                ->with('error', 'No se han podido sincronizar los rankings con Salesforce. Revisa la conexion o las consultas configuradas.');
        }

        return $redirect
            ->with('success', 'Rankings de ventas, compras y coches actualizados correctamente.');
    }
}
