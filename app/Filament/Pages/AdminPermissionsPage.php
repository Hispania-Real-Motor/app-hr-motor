<?php

namespace App\Filament\Pages;

use App\Models\AdminPermissionActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPermissionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $navigationLabel = 'Permisos';
    protected static ?string $title = 'Permisos';
    protected static ?string $slug = 'permisos';
    protected static string|\UnitEnum|null $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 9;
    protected static ?string $breadcrumb = 'Permisos';
    protected string $view = 'filament.pages.admin-permissions';

    public string $activeTab = 'profiles';
    public string $profileSearch = '';
    public string $permissionSearch = '';
    public string $selectedProfileRole = User::ROLE_ADMIN;
    public array $profileGrantKeys = [];
    public array $profileOriginalGrantKeys = [];
    public array $profileRevokedKeys = [];
    public array $profileOriginalRevokedKeys = [];
    public ?int $selectedUserId = null;
    public string $userSearch = '';
    public array $userGrantKeys = [];
    public array $userOriginalGrantKeys = [];

    public static function canAccess(): bool
    {
        return app_user_can_manage_admin_permissions(auth()->user());
    }

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->loadProfileGrants();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function switchTab(string $tab): void
    {
        $this->authorizeAccess();

        abort_unless(in_array($tab, ['profiles', 'users'], true), 404);

        if ($tab === $this->activeTab) {
            return;
        }

        if ($tab === 'profiles') {
            // Leaving the user editor discards its unsaved local state. The
            // selected profile is reloaded from the database below.
            $this->resetUserPermissionState();
            $this->loadProfileGrants();
        } else {
            // Leaving the profile editor must not carry its dirty arrays into
            // the direct-user editor. A user is selected explicitly there.
            $this->resetProfilePermissionState();
        }

        $this->activeTab = $tab;
    }

    public function selectProfile(string $role): void
    {
        $this->authorizeAccess();
        abort_unless(array_key_exists($role, $this->allProfileOptions()), 404);

        // Selection changes are an explicit discard boundary. Never reuse
        // pending edits from the previously selected profile.
        $this->selectedProfileRole = $role;
        $this->resetUserPermissionState();
        $this->loadProfileGrants();
    }

    public function toggleProfilePermission(string $permissionKey): void
    {
        $this->authorizeAccess();
        abort_unless($this->profileSupportsAdditionalGrants(), 422);
        abort_unless(in_array($permissionKey, app_admin_permission_keys(), true), 404);

        $default = in_array($permissionKey, $this->defaultPermissionKeysForRole($this->selectedProfileRole), true);

        if (in_array($permissionKey, $this->profileRevokedKeys, true)) {
            $this->profileRevokedKeys = array_values(array_filter($this->profileRevokedKeys, fn (string $key): bool => $key !== $permissionKey));

            return;
        }

        if (in_array($permissionKey, $this->profileGrantKeys, true)) {
            $this->profileGrantKeys = array_values(array_filter($this->profileGrantKeys, fn (string $key): bool => $key !== $permissionKey));

            if ($default) {
                $this->profileRevokedKeys = [...$this->profileRevokedKeys, $permissionKey];
            }

            return;
        }

        if ($default) {
            $this->profileRevokedKeys = [...$this->profileRevokedKeys, $permissionKey];

            return;
        }

        $this->profileGrantKeys = [...$this->profileGrantKeys, $permissionKey];
    }

    public function saveProfilePermissions(): void
    {
        $this->authorizeAccess();

        $profiles = $this->allProfileOptions();
        abort_unless(array_key_exists($this->selectedProfileRole, $profiles), 404);

        $role = $this->selectedProfileRole;

        if (! $this->profileSupportsAdditionalGrants()) {
            Notification::make()
                ->title('El perfil Admin conserva todos los permisos.')
                ->body('El bypass global del administrador no puede modificarse desde esta pantalla.')
                ->warning()
                ->send();

            return;
        }

        $defaultKeys = $this->defaultPermissionKeysForRole($role);
        $permissionKeys = collect($this->profileGrantKeys)
            ->filter(fn (mixed $key): bool => is_string($key) && in_array($key, app_admin_permission_keys(), true))
            ->reject(fn (string $key): bool => in_array($key, $defaultKeys, true))
            ->unique()
            ->values()
            ->all();
        $revokedKeys = collect($this->profileRevokedKeys)
            ->filter(fn (mixed $key): bool => is_string($key) && in_array($key, $defaultKeys, true))
            ->unique()
            ->values()
            ->all();

        $previousKeys = AdminPermissionGrant::query()
            ->where('group_role', $role)
            ->where('is_revoked', false)
            ->pluck('permission_key')
            ->sort()
            ->values()
            ->all();
        $previousRevokedKeys = AdminPermissionGrant::query()
            ->where('group_role', $role)
            ->where('is_revoked', true)
            ->pluck('permission_key')
            ->sort()
            ->values()
            ->all();
        $newKeys = collect($permissionKeys)->sort()->values()->all();
        $newRevokedKeys = collect($revokedKeys)->sort()->values()->all();

        if ($previousKeys === $newKeys && $previousRevokedKeys === $newRevokedKeys) {
            Notification::make()->title('No hay cambios que guardar.')->info()->send();

            return;
        }

        DB::transaction(function () use ($role, $permissionKeys, $revokedKeys): void {
            AdminPermissionGrant::query()->where('group_role', $role)->delete();

            foreach ($permissionKeys as $permissionKey) {
                AdminPermissionGrant::query()->create([
                    'permission_key' => $permissionKey,
                    'user_id' => null,
                    'group_id' => null,
                    'group_role' => $role,
                    'is_revoked' => false,
                    'granted_by_user_id' => auth()->id(),
                ]);
            }

            foreach ($revokedKeys as $permissionKey) {
                AdminPermissionGrant::query()->create([
                    'permission_key' => $permissionKey,
                    'user_id' => null,
                    'group_id' => null,
                    'group_role' => $role,
                    'is_revoked' => true,
                    'granted_by_user_id' => auth()->id(),
                ]);
            }
        });

        $this->recordPermissionLog(
            targetType: 'profile',
            targetId: null,
            targetName: $profiles[$role]['label'],
            scope: 'group_role',
            changes: [
                'permission_keys' => ['from' => $previousKeys, 'to' => $newKeys],
                'revoked_permission_keys' => ['from' => $previousRevokedKeys, 'to' => $newRevokedKeys],
                'profile_role' => ['to' => $role],
            ],
        );

        $this->loadProfileGrants();
        Notification::make()->title('Permisos del perfil actualizados.')->success()->send();
    }

    public function selectUser(int $userId): void
    {
        $this->authorizeAccess();
        abort_unless(User::query()->whereKey($userId)->exists(), 404);

        // Selection changes are an explicit discard boundary. Load only the
        // direct grants belonging to the newly selected user.
        $this->resetProfilePermissionState();
        $this->selectedUserId = $userId;
        $this->userGrantKeys = [];
        $this->userOriginalGrantKeys = [];
        $this->loadUserGrants();
    }

    public function toggleUserPermission(string $permissionKey): void
    {
        $this->authorizeAccess();
        abort_unless($this->selectedUserId !== null, 422);
        abort_unless(in_array($permissionKey, app_admin_permission_keys(), true), 404);

        $user = $this->selectedUser();
        abort_unless($user instanceof User, 404);
        abort_if($user->is(auth()->user()), 422, 'No puedes modificar tus propios permisos directos desde esta pantalla.');

        $roles = array_values(array_filter([$user->role, $user->extra_role]));
        $inheritedKeys = array_merge(
            array_values(array_diff($this->defaultPermissionKeysForRole($user->role), $this->revokedPermissionKeysForRoles($roles))),
            AdminPermissionGrant::query()
                ->whereIn('group_role', $roles)
                ->where('is_revoked', false)
                ->pluck('permission_key')
                ->all(),
        );
        abort_if(in_array($permissionKey, $inheritedKeys, true), 422);

        $this->userGrantKeys = in_array($permissionKey, $this->userGrantKeys, true)
            ? array_values(array_filter($this->userGrantKeys, fn (string $key): bool => $key !== $permissionKey))
            : [...$this->userGrantKeys, $permissionKey];
    }

    public function saveUserPermissions(): void
    {
        $this->authorizeAccess();
        abort_unless($this->selectedUserId !== null, 422);

        /** @var User $user */
        $user = User::query()->findOrFail($this->selectedUserId);

        if ($user->is(auth()->user())) {
            abort(422, 'No puedes modificar tus propios permisos directos desde esta pantalla.');
        }

        $permissionKeys = collect($this->userGrantKeys)
            ->filter(fn (mixed $key): bool => is_string($key) && in_array($key, app_admin_permission_keys(), true))
            ->unique()
            ->values()
            ->all();
        $previousKeys = $user->adminPermissionGrants()
            ->where('is_revoked', false)
            ->pluck('permission_key')
            ->sort()
            ->values()
            ->all();
        $newKeys = collect($permissionKeys)->sort()->values()->all();

        if ($previousKeys === $newKeys) {
            Notification::make()->title('No hay cambios que guardar.')->info()->send();

            return;
        }

        DB::transaction(function () use ($user, $permissionKeys): void {
            $user->adminPermissionGrants()->delete();

            foreach ($permissionKeys as $permissionKey) {
                $user->adminPermissionGrants()->create([
                    'permission_key' => $permissionKey,
                    'group_id' => null,
                    'group_role' => null,
                    'is_revoked' => false,
                    'granted_by_user_id' => auth()->id(),
                ]);
            }
        });

        $this->recordPermissionLog(
            targetType: 'user',
            targetId: $user->id,
            targetName: $user->name,
            scope: 'user',
            changes: [
                'permission_keys' => ['from' => $previousKeys, 'to' => $newKeys],
                'user_email' => ['to' => $user->email],
            ],
        );

        $this->loadUserGrants();
        Notification::make()->title('Permisos directos actualizados.')->success()->send();
    }

    public function profileOptions(): array
    {
        $profiles = $this->allProfileOptions();

        $search = Str::lower(trim($this->profileSearch));

        if ($search === '') {
            return $profiles;
        }

        return collect($profiles)
            ->filter(fn (array $profile, string $role): bool => Str::contains(Str::lower($profile['label']), $search)
                || Str::contains(Str::lower($profile['type']), $search)
                || Str::contains(Str::lower($role), $search))
            ->all();
    }

    public function allProfileOptions(): array
    {
        return collect(User::baseRoleLabels())
            ->map(fn (string $label, string $role): array => [
                'label' => $label,
                'type' => 'Rol base',
                'users_count' => User::query()->where('role', $role)->count(),
            ])
            ->merge(collect(User::extraRoleLabels())->map(fn (string $label, string $role): array => [
                'label' => $label,
                'type' => 'Rol adicional',
                'users_count' => User::query()->where('extra_role', $role)->count(),
            ]))
            ->all();
    }

    public function baseProfileOptions(): array
    {
        return collect($this->profileOptions())
            ->only(array_keys(User::baseRoleLabels()))
            ->all();
    }

    public function extraProfileOptions(): array
    {
        return collect($this->profileOptions())
            ->only(array_keys(User::extraRoleLabels()))
            ->all();
    }

    public function profilePermissionRows(): array
    {
        $defaultKeys = $this->defaultPermissionKeysForRole($this->selectedProfileRole);

        return $this->permissionRows(
            $defaultKeys,
            $this->profileGrantKeys,
            [],
            $this->profileRevokedKeys,
            true,
        );
    }

    public function profilePermissionsDirty(): bool
    {
        return $this->profileSupportsAdditionalGrants()
            && (
                $this->normalizedKeys($this->profileGrantKeys) !== $this->normalizedKeys($this->profileOriginalGrantKeys)
                || $this->normalizedKeys($this->profileRevokedKeys) !== $this->normalizedKeys($this->profileOriginalRevokedKeys)
            );
    }

    public function profileSupportsAdditionalGrants(): bool
    {
        return $this->selectedProfileRole !== User::ROLE_ADMIN;
    }

    public function userPermissionRows(): array
    {
        $user = $this->selectedUser();

        if (! $user) {
            return [];
        }

        $roleKeys = $this->defaultPermissionKeysForRole($user->role);
        $roleOverrides = AdminPermissionGrant::query()
            ->whereIn('group_role', array_values(array_filter([$user->role, $user->extra_role])))
            ->get(['permission_key', 'is_revoked']);
        $extraKeys = $roleOverrides->where('is_revoked', false)->pluck('permission_key')->all();
        $revokedKeys = $roleOverrides->where('is_revoked', true)->pluck('permission_key')->all();
        return $this->permissionRows($roleKeys, $extraKeys, $this->userGrantKeys, $revokedKeys);
    }

    public function userPermissionsDirty(): bool
    {
        return $this->selectedUserId !== null
            && $this->normalizedKeys($this->userGrantKeys) !== $this->normalizedKeys($this->userOriginalGrantKeys);
    }

    public function users(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->when(trim($this->userSearch) !== '', function (Builder $query): void {
                $search = '%' . trim($this->userSearch) . '%';
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', $search)
                        ->orWhere('email', 'like', $search);
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email', 'role', 'extra_role']);
    }

    public function selectedUser(): ?User
    {
        return $this->selectedUserId ? User::query()->find($this->selectedUserId) : null;
    }

    public function permissionGroups(): array
    {
        return collect($this->filteredPermissionRows())
            ->groupBy('module')
            ->map(fn ($permissions): array => $permissions
                    ->sortBy(fn (array $permission): string => Str::lower(Str::ascii($permission['label'])))
                    ->values()
                    ->all())
            ->sortKeysUsing(fn (string $left, string $right): int => strnatcasecmp(Str::ascii($left), Str::ascii($right)))
            ->all();
    }

    public function filteredPermissionRows(): array
    {
        $rows = $this->activeTab === 'profiles'
            ? $this->profilePermissionRows()
            : $this->userPermissionRows();
        $search = Str::lower(trim($this->permissionSearch));

        if ($search === '') {
            return $rows;
        }

        return collect($rows)
            ->filter(fn (array $permission): bool => Str::contains(Str::lower($permission['label']), $search)
                || Str::contains(Str::lower($permission['module']), $search)
                || Str::contains(Str::lower((string) $permission['description']), $search))
            ->values()
            ->all();
    }

    private function loadProfileGrants(): void
    {
        $overrides = AdminPermissionGrant::query()
            ->where('group_role', $this->selectedProfileRole)
            ->get(['permission_key', 'is_revoked']);
        $this->profileGrantKeys = $overrides->where('is_revoked', false)->pluck('permission_key')->all();
        $this->profileOriginalGrantKeys = $this->profileGrantKeys;
        $this->profileRevokedKeys = $overrides->where('is_revoked', true)->pluck('permission_key')->all();
        $this->profileOriginalRevokedKeys = $this->profileRevokedKeys;
    }

    private function loadUserGrants(): void
    {
        $this->userGrantKeys = $this->selectedUser()?->adminPermissionGrants()->where('is_revoked', false)->pluck('permission_key')->all() ?? [];
        $this->userOriginalGrantKeys = $this->userGrantKeys;
    }

    private function resetProfilePermissionState(): void
    {
        $this->profileGrantKeys = [];
        $this->profileOriginalGrantKeys = [];
        $this->profileRevokedKeys = [];
        $this->profileOriginalRevokedKeys = [];
    }

    private function resetUserPermissionState(): void
    {
        $this->selectedUserId = null;
        $this->userGrantKeys = [];
        $this->userOriginalGrantKeys = [];
    }

    private function normalizedKeys(array $keys): array
    {
        return collect($keys)
            ->filter(fn (mixed $key): bool => is_string($key) && in_array($key, app_admin_permission_keys(), true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function defaultPermissionKeysForRole(string $role): array
    {
        if ($role === User::ROLE_ADMIN) {
            return app_admin_permission_keys();
        }

        return collect(app_admin_permission_definitions())
            ->filter(fn (array $definition): bool => in_array($role, $definition['default_roles'] ?? [], true))
            ->keys()
            ->all();
    }

    private function revokedPermissionKeysForRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        return AdminPermissionGrant::query()
            ->whereIn('group_role', $roles)
            ->where('is_revoked', true)
            ->pluck('permission_key')
            ->all();
    }

    private function permissionRows(array $defaultKeys, array $extraKeys = [], array $directKeys = [], array $revokedKeys = [], bool $isProfile = false): array
    {
        return collect(app_admin_permission_definitions())
            ->map(function (array $definition, string $key) use ($defaultKeys, $extraKeys, $directKeys, $revokedKeys, $isProfile): array {
                if ($key === 'ticket-tools.manage' || Str::startsWith($key, 'backoffice.tickets-it-')) {
                    $module = $this->moduleLabel('tickets-it');
                } elseif ($key === 'backoffice.rankings.manage') {
                    $module = 'Rankings';
                } elseif ($key === 'rankings.view') {
                    $module = 'Rankings';
                } elseif ($key === 'videos.view') {
                    $module = 'Vídeos formación';
                } elseif ($key === 'reports.hr.view') {
                    $module = 'Informes HR';
                } elseif ($key === 'reviews.view') {
                    $module = 'Reseñas';
                } elseif ($key === 'reviews.google.manage') {
                    $module = 'Reseñas';
                } elseif ($key === 'curricula.view') {
                    $module = 'Analizador de currículums';
                } else {
                    $module = $this->moduleLabel(Str::before($key, '.'));
                }

                return [
                    'key' => $key,
                    'label' => $definition['label'] ?? $key,
                    'description' => $definition['description'] ?? null,
                    'module' => $module,
                    'is_default' => in_array($key, $defaultKeys, true),
                    'is_extra' => in_array($key, $extraKeys, true) && ! in_array($key, $defaultKeys, true),
                    'is_direct' => in_array($key, $directKeys, true),
                    'is_revoked' => in_array($key, $revokedKeys, true),
                    'is_checked' => in_array($key, $directKeys, true)
                        || (! in_array($key, $revokedKeys, true) && (in_array($key, $defaultKeys, true) || in_array($key, $extraKeys, true))),
                    'is_locked' => $isProfile
                        ? $this->selectedProfileRole === User::ROLE_ADMIN
                        : (in_array($key, $defaultKeys, true) && ! in_array($key, $revokedKeys, true))
                            || (in_array($key, $extraKeys, true) && ! in_array($key, $directKeys, true)),
                ];
            })
            ->values()
            ->all();
    }

    private function moduleLabel(string $module): string
    {
        return [
            'users' => 'Usuarios',
            'dealerships' => 'Delegaciones',
            'zones' => 'Zonas',
            'contacts' => 'Contactos',
            'ticket-tools' => 'Tipos de incidencia',
            'tickets-it' => 'Tickets IT',
            'magazine' => 'Revista',
            'bulletin' => 'Tablón',
            'notifications' => 'Notificaciones',
            'roles' => 'Visor de roles',
            'chat-retention-holds' => 'Conversaciones',
            'conversation-access' => 'Conversaciones',
            'chat-groups' => 'Conversaciones',
        ][$module] ?? Str::headline($module);
    }

    private function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function recordPermissionLog(string $targetType, ?int $targetId, string $targetName, string $scope, array $changes): void
    {
        AdminPermissionActivityLog::query()->create([
            'action' => AdminPermissionActivityLog::ACTION_PERMISSION_SYNCED,
            'result' => 'success',
            'actor_user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name,
            'actor_email' => auth()->user()?->email,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'scope' => $scope,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
