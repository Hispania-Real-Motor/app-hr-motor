<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesAgendaExtensions;
use App\Models\Dealership;
use App\Models\PurchaseLeaderboardEntry;
use App\Models\SalesLeaderboardEntry;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Notifications\UserOnboardingNotification;
use App\Notifications\UserWelcomeNotification;
use App\Rules\UserEnreachExtensionRule;
use App\Services\CompanyChatDefaultGroupSyncService;
use App\Services\UserDeactivationService;
use App\Services\UserInvitationService;
use App\Services\UserProvisioningService;
use App\Support\UserCreationRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UserController extends Controller
{
    use HandlesAgendaExtensions;

    public function index(Request $request): \Illuminate\Contracts\View\View|JsonResponse
    {
        $search = $request->query('search');
        $normalizedSearch = $this->normalizeAgendaValue($search);
        $status = $request->query('status');
        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc');

        $allowedSorts = ['name', 'email', 'role', 'dealership', 'is_active', 'salesforce_user_id'];
        $sort = in_array($sort, $allowedSorts) ? $sort : 'name';
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $status = in_array($status, ['active', 'pending', 'disabled'], true) ? $status : null;

        $users = User::query()
            ->when(($search && trim((string) $search) !== '') || $normalizedSearch, function ($query) use ($search, $normalizedSearch) {
                $query->where(function ($subquery) use ($search, $normalizedSearch) {
                    if ($search && trim((string) $search) !== '') {
                        $subquery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('role', 'like', "%{$search}%")
                            ->orWhere('extra_role', 'like', "%{$search}%")
                            ->orWhere('dealership', 'like', "%{$search}%")
                            ->orWhere('salesforce_user_id', 'like', "%{$search}%");
                    }

                    if ($normalizedSearch) {
                        $subquery->orWhere('phone', 'like', "%{$normalizedSearch}%")
                            ->orWhere('enreach_extension', 'like', "%{$normalizedSearch}%");
                    }
                });
            })
            ->when($status === 'active', function ($query) {
                $query->where('is_active', true)->whereNull('disabled_at');
            })
            ->when($status === 'pending', function ($query) {
                $query->where('is_active', false)->whereNull('disabled_at');
            })
            ->when($status === 'disabled', function ($query) {
                $query->whereNotNull('disabled_at');
            })
            ->orderBy($sort, $direction)
            ->paginate(10)
            ->withQueryString();

        if ($request->boolean('ajax')) {
            return response()->json([
                'html' => view('users.partials.index-results', [
                    'users' => $users,
                    'authUser' => $request->user(),
                    'search' => $search,
                    'status' => $status,
                    'sort' => $sort,
                    'direction' => $direction,
                ])->render(),
            ]);
        }

        return view('users.index', compact('users', 'search', 'status', 'sort', 'direction'));
    }

    public function create()
    {
        $authUser = request()->user();

        $availableBaseRoles = $authUser?->role === User::ROLE_ADMIN
            ? array_keys(User::baseRoleLabels())
            : [User::ROLE_USER];
        $availableExtraRoles = array_keys(User::extraRoleLabels());
        $availableDealerships = Dealership::query()->orderBy('name')->get();

        return view('users.create', compact('availableBaseRoles', 'availableExtraRoles', 'availableDealerships'));
    }

    public function show(User $user)
    {
        return view('users.show', [
            'user' => $user,
            'rankingPositions' => $this->buildRankingPositions($user),
        ]);
    }

    public function exportCsv(): StreamedResponse
    {
        $users = User::query()
            ->with('assignedDealership')
            ->orderBy('name')
            ->get();

        $filename = 'usuarios-' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($users): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['Usuario', 'Correo', 'Rol', 'Delegación', 'Estado']);

            foreach ($users as $user) {
                fputcsv($output, [
                    $user->name,
                    $user->email,
                    $user->role_label,
                    $user->resolved_dealership_name ?? 'No aplica',
                    $this->resolveCsvStatusLabel($user),
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(Request $request)
    {
        $authUser = $request->user();
        $canManageUsers = app_user_can_manage_admin_tool($authUser, 'users.manage');

        if (! $canManageUsers && $authUser?->role !== User::ROLE_ADMIN && $authUser?->role !== User::ROLE_MANAGER) {
            return redirect()
                ->route('users.index')
                ->with('error', 'No tienes permisos para crear usuarios desde esta vista.');
        }

        $allowedBaseRoles = $authUser?->role === User::ROLE_ADMIN
            ? array_keys(User::baseRoleLabels())
            : [User::ROLE_USER];
        $allowedExtraRoles = array_keys(User::extraRoleLabels());
        $submittedBaseRole = $this->resolveSubmittedBaseRole($request, $authUser);
        $submittedExtraRole = $this->resolveSubmittedExtraRole($request);
        $validated = $request->validate(UserCreationRules::for($submittedBaseRole, $submittedExtraRole, $allowedBaseRoles));

        try {
            $user = DB::transaction(fn (): User => app(UserProvisioningService::class)->create([
                ...$validated,
                'role' => $submittedBaseRole,
                'extra_role' => $submittedExtraRole,
            ]));
            $status = $this->sendInvitationLink($user);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $this->invitationErrorMessage(UserInvitationService::DELIVERY_FAILED));
        }

        $this->storeActivityLog(
            actor: $authUser,
            targetUser: $user,
            action: UserActivityLog::ACTION_CREATED,
        );

        $user->notify(new UserWelcomeNotification());
        $this->sendOnboardingNotificationsIfNeeded($user);

        if ($status !== Password::RESET_LINK_SENT) {
            return redirect()
                ->route('users.index')
                ->with('error', $this->invitationErrorMessage($status));
        }

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario creado correctamente. Le hemos enviado un correo para que establezca su contrasena y active la cuenta.');
    }

    public function destroy(User $user)
    {
        $authUser = request()->user();
        $canManageUsers = app_user_can_manage_admin_tool($authUser, 'users.manage');

        if (! $canManageUsers && $authUser?->role !== User::ROLE_ADMIN) {
            return redirect()
                ->route('users.index')
                ->with('error', 'Solo un administrador puede eliminar definitivamente usuarios.');
        }

        if ($response = $this->ensureCanManageListedUser($authUser, $user, 'eliminar', preventSelf: true)) {
            return $response;
        }

        $this->storeActivityLog(
            actor: $authUser,
            targetUser: $user,
            action: UserActivityLog::ACTION_DELETED,
        );

        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario eliminado definitivamente correctamente.');
    }

    public function disable(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($response = $this->ensureCanManageListedUser($authUser, $user, 'desactivar', preventSelf: true)) {
            return $response;
        }

        if (! $user->is_active || $user->isDisabled()) {
            return redirect()
                ->route('users.index')
                ->with('error', 'Solo puedes desactivar usuarios activos.');
        }

        if ($user->role === User::ROLE_ADMIN) {
            $otherActiveAdmins = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->whereNull('disabled_at')
                ->whereKeyNot($user->id)
                ->count();

            if ($otherActiveAdmins === 0) {
                return redirect()
                    ->route('users.index')
                    ->with('error', 'No puedes desactivar al ultimo administrador activo.');
            }
        }

        $validated = $request->validate([
            'disabled_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $disabledReason = trim((string) ($validated['disabled_reason'] ?? ''));
        if ($disabledReason === '') {
            $disabledReason = 'Baja de empleado';
        }

        try {
            app(UserDeactivationService::class)->deactivate($authUser, $user, $disabledReason);
        } catch (Throwable $e) {
            return redirect()
                ->route('users.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario desactivado correctamente.');
    }

    public function reactivate(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($response = $this->ensureCanManageListedUser($authUser, $user, 'reactivar', preventSelf: false)) {
            return $response;
        }

        if (! $user->isDisabled()) {
            return redirect()
                ->route('users.index')
                ->with('error', 'Solo puedes reactivar usuarios desactivados.');
        }

        DB::transaction(function () use ($authUser, $user): void {
            $user->forceFill([
                'is_active' => true,
                'disabled_at' => null,
                'disabled_by' => null,
                'disabled_reason' => null,
                'remember_token' => Str::random(60),
            ])->save();

            app(CompanyChatDefaultGroupSyncService::class)->syncUser($user, false);
            $this->invalidateUserSessions($user);

            $this->storeActivityLog(
                actor: $authUser,
                targetUser: $user,
                action: UserActivityLog::ACTION_REACTIVATED,
                result: 'success',
            );
        });

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario reactivado correctamente.');
    }

    public function edit(User $user)
    {
        $authUser = request()->user();

        if ($response = $this->ensureCanManageListedUser($authUser, $user, 'editar', preventSelf: app_visible_role($authUser) === User::ROLE_MANAGER)) {
            return $response;
        }

        $availableBaseRoles = $authUser?->role === User::ROLE_ADMIN
            ? array_keys(User::baseRoleLabels())
            : [User::ROLE_USER];
        $availableExtraRoles = array_keys(User::extraRoleLabels());
        $availableDealerships = Dealership::query()->orderBy('name')->get();

        return view('users.edit', compact('user', 'availableBaseRoles', 'availableExtraRoles', 'availableDealerships'));
    }

    public function update(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($response = $this->ensureCanManageListedUser($authUser, $user, 'editar', preventSelf: app_visible_role($authUser) === User::ROLE_MANAGER)) {
            return $response;
        }

        $allowedBaseRoles = $authUser?->role === User::ROLE_ADMIN
            ? array_keys(User::baseRoleLabels())
            : [User::ROLE_USER];
        $allowedExtraRoles = array_keys(User::extraRoleLabels());
        $submittedBaseRole = $this->resolveSubmittedBaseRole($request, $authUser);
        $submittedExtraRole = $this->resolveSubmittedExtraRole($request);
        $isRankedCommercial = $this->isRankedCommercialRole($submittedBaseRole, $submittedExtraRole);
        $isItUser = $submittedExtraRole === User::ROLE_INFORMATION_TECHNOLOGY;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'company_entry_date' => ['required', 'date'],
            'job_position' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in($allowedBaseRoles)],
            'extra_role' => ['nullable', 'string', Rule::in($allowedExtraRoles)],
            'phone' => $this->agendaPhoneRules(),
            'enreach_extension' => array_merge(
                $this->agendaExtensionRules(),
                [new UserEnreachExtensionRule(ignoreUserId: $user->id, action: 'editar')]
            ),
            ...$this->itScheduleRules($isItUser),
            'salesforce_user_id' => [
                Rule::requiredIf($isRankedCommercial),
                'nullable',
                'string',
                'max:255',
                Rule::unique('users', 'salesforce_user_id')->ignore($user->id),
            ],
            'dealership_id' => ['nullable', 'integer', Rule::exists('dealerships', 'id')],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $validator = Validator::make($validated, []);
        $validator->validate();

        $dealership = filled($validated['dealership_id'] ?? null)
            ? Dealership::query()->find($validated['dealership_id'])
            : null;

        $changes = $this->buildChangeSet($user, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company_entry_date' => $validated['company_entry_date'],
            'job_position' => $validated['job_position'],
            'role' => $submittedBaseRole,
            'extra_role' => $submittedExtraRole,
            'phone' => $validated['phone'] ?? null,
            'enreach_extension' => $validated['enreach_extension'] ?? null,
            'it_monday_start' => $isItUser ? $validated['it_monday_start'] : null,
            'it_monday_end' => $isItUser ? $validated['it_monday_end'] : null,
            'it_tuesday_start' => $isItUser ? $validated['it_tuesday_start'] : null,
            'it_tuesday_end' => $isItUser ? $validated['it_tuesday_end'] : null,
            'it_wednesday_start' => $isItUser ? $validated['it_wednesday_start'] : null,
            'it_wednesday_end' => $isItUser ? $validated['it_wednesday_end'] : null,
            'it_thursday_start' => $isItUser ? $validated['it_thursday_start'] : null,
            'it_thursday_end' => $isItUser ? $validated['it_thursday_end'] : null,
            'it_friday_start' => $isItUser ? $validated['it_friday_start'] : null,
            'it_friday_end' => $isItUser ? $validated['it_friday_end'] : null,
            'salesforce_user_id' => $isRankedCommercial ? $validated['salesforce_user_id'] : null,
            'dealership' => $dealership?->name,
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->company_entry_date = $validated['company_entry_date'];
        $user->job_position = $validated['job_position'];
        $user->role = $submittedBaseRole;
        $user->extra_role = $submittedExtraRole;
        $user->phone = $validated['phone'] ?? null;
        $user->enreach_extension = $validated['enreach_extension'] ?? null;
        $user->it_monday_start = $isItUser ? $validated['it_monday_start'] : null;
        $user->it_monday_end = $isItUser ? $validated['it_monday_end'] : null;
        $user->it_tuesday_start = $isItUser ? $validated['it_tuesday_start'] : null;
        $user->it_tuesday_end = $isItUser ? $validated['it_tuesday_end'] : null;
        $user->it_wednesday_start = $isItUser ? $validated['it_wednesday_start'] : null;
        $user->it_wednesday_end = $isItUser ? $validated['it_wednesday_end'] : null;
        $user->it_thursday_start = $isItUser ? $validated['it_thursday_start'] : null;
        $user->it_thursday_end = $isItUser ? $validated['it_thursday_end'] : null;
        $user->it_friday_start = $isItUser ? $validated['it_friday_start'] : null;
        $user->it_friday_end = $isItUser ? $validated['it_friday_end'] : null;
        $user->salesforce_user_id = $this->isRankedCommercialRole($user->role, $user->extra_role)
            ? $validated['salesforce_user_id']
            : null;
        $user->dealership = $dealership?->name;
        $user->dealership_id = $dealership?->id;

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $changes['Contrasena'] = [
                'from' => 'Oculta',
                'to' => 'Actualizada',
            ];
        }

        $user->save();

        if ($changes !== []) {
            $this->storeActivityLog(
                actor: $authUser,
                targetUser: $user,
                action: UserActivityLog::ACTION_UPDATED,
                changes: $changes,
            );
        }

        return redirect()
            ->route('users.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function resendInvitation(Request $request, User $user, UserInvitationService $invitationService)
    {
        if ($response = $this->ensureCanManageListedUser($request->user(), $user, 'reenviar la invitacion', preventSelf: true)) {
            return $response;
        }

        if ($user->isDisabled()) {
            return redirect()
                ->back()
                ->with('error', 'No puedes reenviar la invitacion a un usuario desactivado. Reactivalo primero.');
        }

        if ($user->is_active && ! $user->must_change_password) {
            return redirect()
                ->back()
                ->with('error', 'Solo puedes reenviar la invitacion a usuarios pendientes de activacion.');
        }

        $status = $invitationService->resend($user);

        if ($status === UserInvitationService::DELIVERY_FAILED) {
            return redirect()
                ->back()
                ->with('error', $this->invitationErrorMessage(UserInvitationService::DELIVERY_FAILED));
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return redirect()
                ->back()
                ->with('error', $this->invitationErrorMessage($status));
        }

        return redirect()
            ->back()
            ->with('success', 'Correo de activacion reenviado correctamente.');
    }

    protected function resolveCsvStatusLabel(User $user): string
    {
        if ($user->isDisabled()) {
            return 'Desactivado';
        }

        if ($user->is_active) {
            return 'Activo';
        }

        if ($user->isInvitationExpired()) {
            return 'Caducado';
        }

        return 'Pendiente';
    }

    protected function ensureCanManageListedUser(User $authUser, User $targetUser, string $action, bool $preventSelf = false): ?RedirectResponse
    {
        $canManageUsers = app_user_can_manage_admin_tool($authUser, 'users.manage');

        if ($preventSelf && $authUser->id === $targetUser->id) {
            return redirect()
                ->route('users.index')
                ->with('error', "No puedes {$action} tu propio usuario.");
        }

        if (! $canManageUsers && ! in_array($authUser->role, [User::ROLE_ADMIN, User::ROLE_MANAGER], true)) {
            return redirect()
                ->route('users.index')
                ->with('error', "No tienes permisos para {$action} este usuario.");
        }

        if ($authUser->role !== User::ROLE_ADMIN && ! $targetUser->isCommercialLike()) {
            return redirect()
                ->route('users.index')
                ->with('error', "No tienes permisos para {$action} este usuario.");
        }

        return null;
    }

    protected function invitationErrorMessage(string $status): string
    {
        if ($status === UserInvitationService::DELIVERY_FAILED) {
            return 'El servidor SMTP ha rechazado el destinatario del correo de activación. Comprueba que ese buzón exista y que el servidor de correo de HR Motor lo acepte.';
        }

        return match ($status) {
            Password::RESET_THROTTLED => 'Espera un momento antes de volver a enviar el correo de activación.',
            Password::INVALID_USER => 'No se ha encontrado un usuario válido para enviar el correo de activación.',
            default => 'No se ha podido enviar el correo de activación. Inténtalo de nuevo en unos minutos.',
        };
    }

    protected function sendInvitationLink(User $user): string
    {
        return app(UserInvitationService::class)->resend($user);
    }

    protected function storeActivityLog(
        User $actor,
        User $targetUser,
        string $action,
        array $changes = [],
        string $result = 'success',
        ?string $reason = null,
    ): void
    {
        UserActivityLog::query()->create([
            'action' => $action,
            'result' => $result,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'target_user_id' => $targetUser->id,
            'target_name' => $targetUser->name,
            'target_email' => $targetUser->email,
            'target_role' => $targetUser->role,
            'target_dealership' => $targetUser->dealership,
            'changes' => $changes === [] ? null : $changes,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    protected function buildChangeSet(User $user, array $newValues): array
    {
        $labels = [
            'name' => 'Nombre',
            'email' => 'Email',
            'company_entry_date' => 'Día que entró en la empresa',
            'job_position' => 'Puesto',
            'phone' => 'Telefono',
            'enreach_extension' => 'Extension Enreach',
            'it_monday_start' => 'Horario lunes inicio',
            'it_monday_end' => 'Horario lunes fin',
            'it_tuesday_start' => 'Horario martes inicio',
            'it_tuesday_end' => 'Horario martes fin',
            'it_wednesday_start' => 'Horario miercoles inicio',
            'it_wednesday_end' => 'Horario miercoles fin',
            'it_thursday_start' => 'Horario jueves inicio',
            'it_thursday_end' => 'Horario jueves fin',
            'it_friday_start' => 'Horario viernes inicio',
            'it_friday_end' => 'Horario viernes fin',
            'role' => 'Rol',
            'extra_role' => 'Rol adicional',
            'salesforce_user_id' => 'ID Salesforce',
            'dealership' => 'Delegacion',
        ];

        return collect($newValues)
            ->filter(fn ($value, $field) => $this->compareUserFieldValue($user, $field, $value))
            ->mapWithKeys(fn ($value, $field) => [
                $labels[$field] ?? $field => [
                    'from' => $this->displayUserFieldValue($this->getUserFieldValue($user, $field), $field),
                    'to' => $this->displayUserFieldValue($value, $field),
                ],
            ])
            ->all();
    }

    protected function getUserFieldValue(User $user, string $field): mixed
    {
        if ($field === 'company_entry_date') {
            return $user->getRawOriginal($field);
        }

        return $user->{$field};
    }

    protected function compareUserFieldValue(User $user, string $field, mixed $newValue): bool
    {
        if ($field === 'company_entry_date') {
            return $this->normalizeDateValue($user->getRawOriginal($field)) !== $this->normalizeDateValue($newValue);
        }

        return $user->{$field} !== $newValue;
    }

    protected function displayUserFieldValue(mixed $value, string $field): mixed
    {
        if ($field === 'company_entry_date') {
            $normalized = $this->normalizeDateValue($value);

            return $normalized ? $normalized->format('d/m/Y') : null;
        }

        return $value;
    }

    protected function normalizeDateValue(mixed $value): ?\Illuminate\Support\Carbon
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->startOfDay();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($value)->startOfDay();
    }

    protected function buildRankingPositions(User $user): array
    {
        $positions = [
            'sales' => [
                'position' => null,
                'total' => 0,
            ],
            'purchases' => [
                'position' => null,
                'total' => 0,
            ],
        ];

        if (! $user->isRankedCommercial()) {
            return $positions;
        }

        if (Schema::hasTable('sales_leaderboard_entries')) {
            $salesEntry = SalesLeaderboardEntry::query()
                ->when($this->excludedLeaderboardUserIds() !== [], function ($query) {
                    $query->whereNotIn('salesforce_user_id', $this->excludedLeaderboardUserIds());
                })
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id);

                    if ($user->salesforce_user_id) {
                        $query->orWhere('salesforce_user_id', $user->salesforce_user_id);
                    }
                })
                ->orderBy('ranking_position')
                ->first();

            $positions['sales'] = [
                'position' => $salesEntry?->ranking_position,
                'total' => (int) round((float) ($salesEntry?->total_sales ?? 0)),
            ];
        }

        if (Schema::hasTable('purchase_leaderboard_entries')) {
            $purchaseEntry = PurchaseLeaderboardEntry::query()
                ->when($this->excludedLeaderboardUserIds() !== [], function ($query) {
                    $query->whereNotIn('salesforce_user_id', $this->excludedLeaderboardUserIds());
                })
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id);

                    if ($user->salesforce_user_id) {
                        $query->orWhere('salesforce_user_id', $user->salesforce_user_id);
                    }
                })
                ->orderBy('ranking_position')
                ->first();

            $positions['purchases'] = [
                'position' => $purchaseEntry?->ranking_position,
                'total' => (int) round((float) ($purchaseEntry?->total_purchases ?? 0)),
            ];
        }

        return $positions;
    }

    protected function excludedLeaderboardUserIds(): array
    {
        return config('services.salesforce.excluded_leaderboard_user_ids', []);
    }

    protected function isRankedCommercialRole(?string $baseRole, ?string $extraRole): bool
    {
        return $baseRole === User::ROLE_USER
            && in_array($extraRole, [
                User::ROLE_COMMERCIAL,
                User::ROLE_STORE_MANAGER,
                User::ROLE_AREA_MANAGER,
                User::ROLE_HR_NEWCARS,
            ], true);
    }

    protected function resolveSubmittedBaseRole(Request $request, User $authUser): string
    {
        $baseRole = app_visible_role($authUser) === User::ROLE_ADMIN
            ? $request->input('role')
            : User::ROLE_USER;

        return $baseRole;
    }

    protected function resolveSubmittedExtraRole(Request $request): ?string
    {
        $extraRole = $request->input('extra_role');

        if (blank($extraRole) && $request->boolean('is_store_manager')) {
            $extraRole = User::ROLE_STORE_MANAGER;
        }

        return filled($extraRole) ? $extraRole : null;
    }

    protected function itScheduleRules(bool $required): array
    {
        return [
            'it_monday_start' => $this->itScheduleFieldRules($required),
            'it_monday_end' => $this->itScheduleFieldRules($required),
            'it_tuesday_start' => $this->itScheduleFieldRules($required),
            'it_tuesday_end' => $this->itScheduleFieldRules($required),
            'it_wednesday_start' => $this->itScheduleFieldRules($required),
            'it_wednesday_end' => $this->itScheduleFieldRules($required),
            'it_thursday_start' => $this->itScheduleFieldRules($required),
            'it_thursday_end' => $this->itScheduleFieldRules($required),
            'it_friday_start' => $this->itScheduleFieldRules($required),
            'it_friday_end' => $this->itScheduleFieldRules($required),
        ];
    }

    protected function itScheduleFieldRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'date_format:H:i',
        ];
    }

    protected function invalidateUserSessions(User $user): void
    {
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();
    }

    protected function sendOnboardingNotificationsIfNeeded(User $user): void
    {
        if (! app_user_has_any_role($user, [
            User::ROLE_COMMERCIAL,
            User::ROLE_STORE_MANAGER,
            User::ROLE_AREA_MANAGER,
        ])) {
            return;
        }

        foreach ($this->onboardingNotifications() as $notification) {
            $user->notify($notification);
        }
    }

    /**
     * @return array<int, UserOnboardingNotification>
     */
    protected function onboardingNotifications(): array
    {
        return [
            UserOnboardingNotification::agenda(),
            UserOnboardingNotification::salesRanking(),
            UserOnboardingNotification::vehicleRanking(),
            UserOnboardingNotification::web(),
            UserOnboardingNotification::chat(),
            UserOnboardingNotification::videos(),
        ];
    }
}
