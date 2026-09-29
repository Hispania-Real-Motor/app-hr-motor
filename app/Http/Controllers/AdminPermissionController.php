<?php

namespace App\Http\Controllers;

use App\Models\AdminPermissionActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\AdminPermissionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Str;

class AdminPermissionController extends Controller
{

    public function syncTargetPermissions(Request $request): RedirectResponse
    {
        $this->authorizeAdminManager();
        $this->ensureSchemaReady();

        $validated = $request->validate([
            'target_type' => ['required', Rule::in(['user', 'group'])],
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_role' => ['nullable', 'string', Rule::in(array_keys(User::extraRoleLabels()))],
            'permission_keys' => ['nullable', 'array'],
            'permission_keys.*' => ['string', Rule::in(app_admin_permission_keys())],
        ]);

        $targetType = $validated['target_type'];
        $permissionKeys = array_values(array_unique($validated['permission_keys'] ?? []));
        $targetUserId = $targetType === 'user' ? (int) ($validated['target_user_id'] ?? 0) : null;
        $targetRole = $targetType === 'group' ? ($validated['target_role'] ?? null) : null;

        abort_unless($targetType === 'user' ? $targetUserId > 0 : filled($targetRole), 422);

        DB::transaction(function () use ($targetType, $targetUserId, $targetRole, $permissionKeys, $request): void {
            if ($targetType === 'user') {
                AdminPermissionGrant::query()
                    ->where('user_id', $targetUserId)
                    ->delete();

                foreach ($permissionKeys as $permissionKey) {
                    AdminPermissionGrant::query()->create([
                        'permission_key' => $permissionKey,
                        'user_id' => $targetUserId,
                        'group_id' => null,
                        'group_role' => null,
                        'granted_by_user_id' => $request->user()?->id,
                    ]);
                }
            } else {
                AdminPermissionGrant::query()
                    ->where('group_role', $targetRole)
                    ->delete();

                foreach ($permissionKeys as $permissionKey) {
                    AdminPermissionGrant::query()->create([
                        'permission_key' => $permissionKey,
                        'user_id' => null,
                        'group_id' => null,
                        'group_role' => $targetRole,
                        'granted_by_user_id' => $request->user()?->id,
                    ]);
                }
            }
        });

        $targetName = $targetType === 'user'
            ? User::query()->whereKey($targetUserId)->value('name')
            : (User::extraRoleLabels()[$targetRole] ?? $targetRole);

        $this->recordLog(
            actor: $request->user(),
            action: AdminPermissionActivityLog::ACTION_PERMISSION_SYNCED,
            targetType: $targetType,
            targetId: $targetUserId,
            targetName: $targetName,
            permissionKey: null,
            scope: $targetType === 'user' ? 'user' : 'group',
            changes: [
                'permission_keys' => ['to' => $permissionKeys],
                'target_role' => ['to' => $targetRole],
                'target_user_id' => ['to' => $targetUserId],
            ],
        );

        return redirect()
            ->route('admin.permissions.index', array_filter([
                'target_type' => $targetType,
                'target_user_id' => $targetUserId,
                'target_role' => $targetRole,
                'users_search' => $request->string('users_search')->toString() ?: null,
                'groups_search' => $request->string('groups_search')->toString() ?: null,
            ], static fn ($value) => $value !== null && $value !== ''))
            ->with('success', 'Permisos actualizados correctamente.');
    }

    private function resolveSelectedTarget(Request $request, bool $missingTables): ?array
    {
        if ($missingTables) {
            return null;
        }

        $targetType = $request->string('target_type')->toString();

        if ($targetType === 'user') {
            $userId = $request->integer('target_user_id');
            if ($userId <= 0) {
                return null;
            }

            $user = User::query()
                ->select(['id', 'name', 'email', 'role', 'extra_role', 'is_active'])
                ->find($userId);

            if (! $user) {
                return null;
            }

            return [
                'type' => 'user',
                'id' => $user->id,
                'label' => $user->name,
                'meta' => $user->email,
                'description' => $user->extra_role ? (User::extraRoleLabels()[$user->extra_role] ?? $user->extra_role) : 'Sin rol extra',
            ];
        }

        if ($targetType === 'group') {
            $targetRole = $request->string('target_role')->toString();

            if ($targetRole === '' || ! array_key_exists($targetRole, User::extraRoleLabels())) {
                return null;
            }

            return [
                'type' => 'group',
                'role' => $targetRole,
                'label' => User::extraRoleLabels()[$targetRole] ?? $targetRole,
                'meta' => $targetRole,
                'description' => 'Grupo virtual basado en `extra_role`.',
            ];
        }

        return null;
    }

    private function selectedPermissionKeysForTarget(array $target): Collection
    {
        if ($target['type'] === 'user') {
            return AdminPermissionGrant::query()
                ->where('user_id', $target['id'])
                ->where('is_revoked', false)
                ->pluck('permission_key')
                ->values();
        }

        return AdminPermissionGrant::query()
            ->where('group_role', $target['role'])
            ->where('is_revoked', false)
            ->pluck('permission_key')
            ->values();
    }

    private function emptyPaginator(string $pageName, Request $request): LengthAwarePaginator
    {
        return (new LengthAwarePaginator([], 0, 10, 1, [
            'path' => $request->url(),
            'pageName' => $pageName,
        ]))->appends($request->except($pageName));
    }

    private function filteredGroups(string $search): Collection
    {
        $groups = DB::table('users')
            ->selectRaw('extra_role as role_key, COUNT(*) as users_count')
            ->whereNotNull('extra_role')
            ->groupBy('extra_role')
            ->orderBy('extra_role')
            ->get();

        if ($search === '') {
            return $groups->values();
        }

        $searchLower = Str::lower($search);

        return $groups
            ->filter(function ($group) use ($searchLower): bool {
                $roleKey = (string) $group->role_key;
                $roleLabel = User::extraRoleLabels()[$roleKey] ?? $roleKey;

                return Str::contains(Str::lower($roleKey), $searchLower)
                    || Str::contains(Str::lower($roleLabel), $searchLower);
            })
            ->values();
    }

    private function paginateCollection(Collection $items, int $perPage, string $pageName, Request $request): LengthAwarePaginator
    {
        $currentPage = max(1, (int) $request->integer($pageName, 1));
        $pageItems = $items->forPage($currentPage, $perPage)->values();

        return (new LengthAwarePaginator($pageItems, $items->count(), $perPage, $currentPage, [
            'path' => $request->url(),
            'pageName' => $pageName,
        ]))->appends($request->except($pageName));
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $this->authorizeAdminManager();
        $this->ensureSchemaReady();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:admin_permission_groups,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $group = AdminPermissionGroup::query()->create($validated);

        $this->recordLog(
            actor: $request->user(),
            action: AdminPermissionActivityLog::ACTION_GROUP_CREATED,
            targetType: 'group',
            targetId: $group->id,
            targetName: $group->name,
            changes: [
                'name' => ['from' => null, 'to' => $group->name],
                'description' => ['from' => null, 'to' => $group->description],
            ],
        );

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Grupo creado correctamente.');
    }

    public function updateGroup(Request $request, AdminPermissionGroup $group): RedirectResponse
    {
        $this->authorizeAdminManager();
        $this->ensureSchemaReady();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('admin_permission_groups', 'name')->ignore($group->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $changes = [];

        if ($group->name !== $validated['name']) {
            $changes['name'] = ['from' => $group->name, 'to' => $validated['name']];
        }

        if ($group->description !== ($validated['description'] ?? null)) {
            $changes['description'] = ['from' => $group->description, 'to' => $validated['description'] ?? null];
        }

        $group->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $memberIds = array_map('intval', $validated['member_ids'] ?? []);
        $group->members()->sync($memberIds);

        $this->recordLog(
            actor: $request->user(),
            action: AdminPermissionActivityLog::ACTION_GROUP_UPDATED,
            targetType: 'group',
            targetId: $group->id,
            targetName: $group->name,
            changes: array_filter([
                'fields' => $changes ?: null,
                'member_ids' => ['to' => $memberIds],
            ]),
        );

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Grupo actualizado correctamente.');
    }

    public function destroyGroup(Request $request, AdminPermissionGroup $group): RedirectResponse
    {
        $this->authorizeAdminManager();
        $this->ensureSchemaReady();

        $changes = [
            'members' => ['to' => $group->members()->pluck('name')->all()],
            'permissions' => ['to' => $group->grants()->pluck('permission_key')->all()],
        ];

        $groupName = $group->name;
        $group->delete();

        $this->recordLog(
            actor: $request->user(),
            action: AdminPermissionActivityLog::ACTION_GROUP_DELETED,
            targetType: 'group',
            targetId: $group->id,
            targetName: $groupName,
            changes: $changes,
        );

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Grupo eliminado correctamente.');
    }

    public function syncPermission(Request $request, string $permissionKey): RedirectResponse
    {
        $this->authorizeAdminManager();
        $this->ensureSchemaReady();

        abort_unless(array_key_exists($permissionKey, app_admin_permission_definitions()), 404);

        $validated = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:admin_permission_groups,id'],
        ]);

        $userIds = array_values(array_unique(array_map('intval', $validated['user_ids'] ?? [])));
        $groupIds = array_values(array_unique(array_map('intval', $validated['group_ids'] ?? [])));

        DB::transaction(function () use ($permissionKey, $userIds, $groupIds, $request): void {
            AdminPermissionGrant::query()
                ->where('permission_key', $permissionKey)
                ->whereNotNull('user_id')
                ->delete();

            AdminPermissionGrant::query()
                ->where('permission_key', $permissionKey)
                ->whereNotNull('group_id')
                ->delete();

            foreach ($userIds as $userId) {
                AdminPermissionGrant::query()->create([
                    'permission_key' => $permissionKey,
                    'user_id' => $userId,
                    'group_id' => null,
                    'granted_by_user_id' => $request->user()?->id,
                ]);
            }

            foreach ($groupIds as $groupId) {
                AdminPermissionGrant::query()->create([
                    'permission_key' => $permissionKey,
                    'user_id' => null,
                    'group_id' => $groupId,
                    'granted_by_user_id' => $request->user()?->id,
                ]);
            }
        });

        $this->recordLog(
            actor: $request->user(),
            action: AdminPermissionActivityLog::ACTION_PERMISSION_SYNCED,
            targetType: 'permission',
            targetId: null,
            targetName: app_admin_permission_definitions()[$permissionKey]['label'] ?? $permissionKey,
            permissionKey: $permissionKey,
            scope: 'users_and_groups',
            changes: [
                'user_ids' => ['to' => $userIds],
                'group_ids' => ['to' => $groupIds],
            ],
        );

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Permisos actualizados correctamente.');
    }

    private function authorizeAdminManager(): void
    {
        abort_unless(app_user_can_manage_admin_permissions(auth()->user()), 403);
    }

    private function ensureSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('admin_permission_groups')
                && Schema::hasTable('admin_permission_group_user')
                && Schema::hasTable('admin_permission_grants')
                && Schema::hasTable('admin_permission_activity_logs')
                && Schema::hasColumn('admin_permission_grants', 'group_role'),
            503,
        );
    }

    private function recordLog(
        ?User $actor,
        string $action,
        string $targetType,
        ?int $targetId,
        ?string $targetName,
        ?string $permissionKey = null,
        ?string $scope = null,
        array $changes = [],
    ): void {
        AdminPermissionActivityLog::query()->create([
            'action' => $action,
            'result' => 'success',
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'permission_key' => $permissionKey,
            'scope' => $scope,
            'changes' => $changes === [] ? null : $changes,
            'created_at' => now(),
        ]);
    }
}
