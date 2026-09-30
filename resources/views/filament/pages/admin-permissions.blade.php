<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Gestiona los permisos del backoffice</x-slot>
            <x-slot name="description">Los permisos predeterminados proceden de la configuración. Admin conserva el acceso completo; en los demás perfiles puedes conceder o revocar permisos.</x-slot>
            <x-filament::tabs label="Secciones de permisos" contained>
                <x-filament::tabs.item wire:click="switchTab('profiles')" :active="$activeTab === 'profiles'" icon="heroicon-o-user-group">Permisos por perfil</x-filament::tabs.item>
                <x-filament::tabs.item wire:click="switchTab('users')" :active="$activeTab === 'users'" icon="heroicon-o-user">Permisos directos por usuario</x-filament::tabs.item>
            </x-filament::tabs>
        </x-filament::section>

        @if ($activeTab === 'profiles')
            <div wire:key="permissions-mode-profiles">
            <x-filament::section>
                <x-slot name="heading">Perfiles</x-slot>
                <x-slot name="description">Selecciona un perfil para consultar y gestionar sus permisos adicionales.</x-slot>

                <div class="mb-6 max-w-2xl">
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="search" wire:model.live.debounce.300ms="profileSearch" placeholder="Buscar perfil" aria-label="Buscar perfil" />
                    </x-filament::input.wrapper>
                </div>

                @php
                    $baseProfiles = $this->baseProfileOptions();
                    $extraProfiles = $this->extraProfileOptions();
                @endphp

                <div class="space-y-8">
                    @if (count($baseProfiles) > 0)
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Roles base</h3>
                        <div class="flex flex-wrap gap-3" role="list" aria-label="Roles base">
                            @foreach ($baseProfiles as $role => $profile)
                                <x-filament::button wire:key="profile-select-{{ $role }}" wire:click="selectProfile('{{ $role }}')" :color="$selectedProfileRole === $role ? 'primary' : 'gray'" size="sm" :aria-pressed="$selectedProfileRole === $role" role="listitem">
                                    {{ $profile['label'] }}
                                </x-filament::button>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    @if (count($extraProfiles) > 0)
                    <div>
                        <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Roles adicionales</h3>
                        <div class="flex flex-wrap gap-3" role="list" aria-label="Roles adicionales">
                            @foreach ($extraProfiles as $role => $profile)
                                <x-filament::button wire:key="profile-select-{{ $role }}" wire:click="selectProfile('{{ $role }}')" :color="$selectedProfileRole === $role ? 'primary' : 'gray'" size="sm" :aria-pressed="$selectedProfileRole === $role" role="listitem">
                                    {{ $profile['label'] }}
                                </x-filament::button>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    @if (count($baseProfiles) === 0 && count($extraProfiles) === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">No se han encontrado perfiles.</p>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                    <x-slot name="heading">{{ $this->allProfileOptions()[$selectedProfileRole]['label'] ?? $selectedProfileRole }}</x-slot>
                    <x-slot name="description">
                        <span class="inline-flex flex-wrap items-center gap-2"><x-filament::badge color="gray">{{ $this->allProfileOptions()[$selectedProfileRole]['type'] ?? 'Perfil' }}</x-filament::badge><span>{{ $this->allProfileOptions()[$selectedProfileRole]['users_count'] ?? 0 }} {{ (($this->allProfileOptions()[$selectedProfileRole]['users_count'] ?? 0) === 1) ? 'usuario asociado' : 'usuarios asociados' }}</span></span>
                    </x-slot>

                    <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                        @if ($selectedProfileRole === \App\Models\User::ROLE_ADMIN)
                            Admin tiene acceso completo al backoffice por su bypass global. Sus permisos aparecen concedidos y bloqueados para proteger este acceso.
                        @elseif ($this->profileSupportsAdditionalGrants())
                            Los permisos predeterminados proceden de la configuración y están bloqueados. Puedes activar o desactivar los permisos adicionales de este perfil.
                        @else
                            Este rol base conserva sus permisos predeterminados. Para ampliar el acceso de una persona concreta, utiliza los permisos directos por usuario.
                        @endif
                    </div>

                    <div class="mb-6 max-w-2xl">
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input type="search" wire:model.live.debounce.300ms="permissionSearch" placeholder="Buscar permiso" aria-label="Buscar permiso" />
                        </x-filament::input.wrapper>
                    </div>

                    @php
                        $permissionGroups = $this->permissionGroups();
                    @endphp

                    <div class="space-y-4">
                        @forelse ($permissionGroups as $module => $permissions)
                            <section class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                                <div class="border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]"><h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $module }}</h3></div>
                                <div class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($permissions as $permission)
                                        <label wire:key="profile-{{ $selectedProfileRole }}-permission-{{ $permission['key'] }}" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-4 gap-y-1 px-4 py-4 transition {{ $permission['is_locked'] ? 'cursor-not-allowed bg-gray-50/50 dark:bg-white/[0.02]' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]' }}">
                                            <div class="row-span-2 pt-0.5">
                                                <x-filament::input.checkbox value="{{ $permission['key'] }}" :checked="$permission['is_checked']" wire:click="toggleProfilePermission('{{ $permission['key'] }}')" :disabled="$permission['is_locked']" class="shrink-0" />
                                            </div>
                                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $permission['label'] }}</span>
                                                @if ($selectedProfileRole === \App\Models\User::ROLE_ADMIN)
                                                    <x-filament::badge color="gray" size="sm">Acceso total</x-filament::badge>
                                                @elseif ($permission['is_revoked'])
                                                    <x-filament::badge color="warning" size="sm">Revocado</x-filament::badge>
                                                @elseif ($permission['is_default'])
                                                    <x-filament::badge color="gray" size="sm" tooltip="Este permiso procede de la configuración del rol y se puede revocar desde aquí.">Concedido por defecto</x-filament::badge>
                                                @elseif ($permission['is_extra'])
                                                    <x-filament::badge color="primary" size="sm">Concedido manualmente</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="gray" size="sm">No concedido</x-filament::badge>
                                                @endif
                                            </div>
                                            <p class="col-start-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $permission['description'] }}</p>
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">No se han encontrado permisos.</p>
                        @endforelse
                    </div>

                    <div class="mt-10 flex justify-end border-t border-gray-200 pt-6 dark:border-white/10">
                        <x-filament::button wire:click="saveProfilePermissions" wire:loading.attr="disabled" wire:target="saveProfilePermissions" :disabled="! $this->profilePermissionsDirty()" icon="heroicon-o-check"><span wire:loading.remove wire:target="saveProfilePermissions">Guardar permisos adicionales</span><span wire:loading wire:target="saveProfilePermissions">Guardando permisos...</span></x-filament::button>
                    </div>
            </x-filament::section>
            </div>
        @else
            <div class="grid gap-6 xl:grid-cols-12" wire:key="permissions-mode-users">
                <div class="xl:col-span-4"><x-filament::section>
                    <x-slot name="heading">Usuarios</x-slot><x-slot name="description">Busca un usuario para consultar y gestionar sus permisos directos.</x-slot>
                    <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="userSearch" placeholder="Buscar por nombre o correo" aria-label="Buscar usuarios" /></x-filament::input.wrapper>
                    <div class="mt-4 divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-white/10 dark:border-white/10">
                        @forelse ($this->users() as $user)
                            <button type="button" wire:key="user-select-{{ $user->id }}" wire:click="selectUser({{ $user->id }})" aria-pressed="{{ $selectedUserId === $user->id ? 'true' : 'false' }}" class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:hover:bg-white/5 {{ $selectedUserId === $user->id ? 'bg-primary-50 dark:bg-primary-500/10' : 'bg-transparent' }}"><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-gray-950 dark:text-white">{{ $user->name }}</span><span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</span><span class="mt-2 flex flex-wrap gap-1.5"><x-filament::badge color="gray" size="sm">{{ \App\Models\User::baseRoleLabels()[$user->role] ?? $user->role }}</x-filament::badge>@if ($user->extra_role)<x-filament::badge color="gray" size="sm">{{ \App\Models\User::extraRoleLabels()[$user->extra_role] ?? $user->extra_role }}</x-filament::badge>@endif</span></span>@if ($selectedUserId === $user->id)<x-filament::icon icon="heroicon-m-check" class="h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" />@endif</button>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No se han encontrado usuarios.</div>
                        @endforelse
                    </div>
                </x-filament::section></div>
                <div class="xl:col-span-8"><x-filament::section>
                    @if ($this->selectedUser())
                        <x-slot name="heading">{{ $this->selectedUser()->name }}</x-slot><x-slot name="description"><span class="inline-flex flex-wrap items-center gap-2"><span>{{ $this->selectedUser()->email }}</span><x-filament::badge color="gray">{{ \App\Models\User::baseRoleLabels()[$this->selectedUser()->role] ?? $this->selectedUser()->role }}</x-filament::badge>@if ($this->selectedUser()->extra_role)<x-filament::badge color="gray">{{ \App\Models\User::extraRoleLabels()[$this->selectedUser()->extra_role] ?? $this->selectedUser()->extra_role }}</x-filament::badge>@endif</span></x-slot>
                        <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">Los permisos heredados del rol base y del rol adicional son informativos. Solo puedes modificar los permisos directos de este usuario.</div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            @foreach ($this->permissionGroups() as $module => $permissions)
                                <section class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10"><div class="border-b border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]"><h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $module }}</h3></div><div class="divide-y divide-gray-200 dark:divide-white/10">
                                    @foreach ($permissions as $permission)
                                        <label wire:key="user-{{ $selectedUserId }}-permission-{{ $permission['key'] }}" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-4 gap-y-1 px-4 py-4 transition {{ $permission['is_locked'] ? 'cursor-not-allowed bg-gray-50/50 dark:bg-white/[0.02]' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]' }}">
                                            <div class="row-span-2 pt-0.5">
                                                <x-filament::input.checkbox value="{{ $permission['key'] }}" :checked="$permission['is_checked']" wire:click="toggleUserPermission('{{ $permission['key'] }}')" :disabled="$permission['is_locked']" class="shrink-0" />
                                            </div>
                                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $permission['label'] }}</span>
                                                @if ($permission['is_direct'])
                                                    <x-filament::badge color="primary" size="sm">Concedido directamente</x-filament::badge>
                                                @elseif ($permission['is_revoked'])
                                                    <x-filament::badge color="warning" size="sm">Revocado</x-filament::badge>
                                                @elseif ($permission['is_default'])
                                                    <x-filament::badge color="gray" size="sm">Concedido por defecto</x-filament::badge>
                                                @elseif ($permission['is_extra'])
                                                    <x-filament::badge color="gray" size="sm">Concedido por rol adicional</x-filament::badge>
                                                @else
                                                    <x-filament::badge color="gray" size="sm">No concedido</x-filament::badge>
                                                @endif
                                            </div>
                                            <p class="col-start-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $permission['description'] }}</p>
                                        </label>
                                    @endforeach
                                </div></section>
                            @endforeach
                        </div>
                        <div class="mt-10 flex justify-end border-t border-gray-200 pt-6 dark:border-white/10"><x-filament::button wire:click="saveUserPermissions" wire:loading.attr="disabled" wire:target="saveUserPermissions" :disabled="! $this->userPermissionsDirty()" icon="heroicon-o-check"><span wire:loading.remove wire:target="saveUserPermissions">Guardar permisos directos</span><span wire:loading wire:target="saveUserPermissions">Guardando permisos...</span></x-filament::button></div>
                    @else
                        <div class="flex min-h-80 items-center justify-center text-center text-sm text-gray-500 dark:text-gray-400">Selecciona un usuario para consultar y modificar sus permisos directos.</div>
                    @endif
                </x-filament::section></div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
