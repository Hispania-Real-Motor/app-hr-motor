<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoleViewerController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(app_role_viewer_enabled($user), 403);

        $allowedRoles = array_keys(app_role_viewer_options($user));

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:' . implode(',', $allowedRoles)],
        ]);

        if ($validated['role'] === $user->role) {
            session()->forget('role_viewer.active_role');
        } else {
            session(['role_viewer.active_role' => $validated['role']]);
        }

        return redirect()
            ->route('home')
            ->with('success', 'Rol cambiado a ' . (User::roleLabels()[$validated['role']] ?? 'Admin') . '.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(app_role_viewer_enabled($user), 403);

        $previousRole = session('role_viewer.active_role');
        session()->forget('role_viewer.active_role');

        $resetMessage = $previousRole ? 'Has vuelto a tu rol.' : 'Ya estabas en tu rol.';

        return redirect()
            ->route('home')
            ->with('success', $resetMessage);
    }
}
