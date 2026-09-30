<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="inline-flex cursor-default items-center gap-2 self-start rounded-full border border-brand-secondary/10 bg-white px-3 py-2 shadow-[0_1px_0_rgba(31,41,68,0.03)] transition duration-200 hover:border-brand-primary/10 hover:bg-brand-primary/5 hover:shadow-[0_6px_18px_rgba(31,41,68,0.04)]">
            <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-brand-primary/10 px-2 text-sm font-bold text-brand-primary">
                {{ $results->total() }}
            </span>

            <div class="flex flex-col">
                <span class="text-[13px] font-semibold leading-tight text-brand-secondary">
                    {{ $results->total() === 1 ? 'Resultado encontrado' : 'Resultados encontrados' }}
                </span>
                <span class="text-[11px] leading-tight text-brand-secondary/55">
                    Agenda actualizada en vivo
                </span>
            </div>
        </div>

        @if ($results->hasPages())
            <p class="text-xs font-medium uppercase tracking-[0.12em] text-brand-secondary/40">
                Navega entre páginas sin recargar
            </p>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-secondary/10">
                <thead class="bg-brand-secondary/5">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Nombre</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Correo</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Teléfono</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Extensión Enreach</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Tipo</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-brand-secondary/10 bg-white">
                    @forelse ($results as $entry)
                        <tr class="transition duration-200 ease-out hover:bg-brand-secondary/4 hover:shadow-[inset_0_1px_0_rgba(31,41,68,0.02)]">
                            <td class="px-6 py-4 text-sm font-semibold text-brand-secondary">
                                <a href="{{ $entry['route'] }}" class="flex items-center gap-3 transition hover:opacity-80">
                                    <img src="{{ $entry['avatar'] }}" alt="Avatar de {{ $entry['name'] }}" class="h-11 w-11 rounded-full object-cover ring-1 ring-brand-secondary/10">
                                    <div>
                                        <span class="block">{{ $entry['name'] }}</span>
                                        <span class="mt-1 block text-xs font-medium text-brand-secondary/60">{{ $entry['subtitle'] ?? 'Sin delegación' }}</span>
                                    </div>
                                </a>
                            </td>
                            <td class="px-6 py-4 text-sm text-brand-secondary/80">{{ $entry['email'] ?: 'No disponible' }}</td>
                            <td class="px-6 py-4 text-sm text-brand-secondary/80">{{ $entry['phone'] ?: 'No disponible' }}</td>
                            <td class="px-6 py-4 text-sm text-brand-secondary/80">
                                {{ $entry['enreach_extension'] ?? 'No disponible' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-brand-secondary/80">
                                <span class="inline-flex min-w-[7rem] justify-center rounded-full px-3 py-1 text-center text-xs font-semibold uppercase tracking-wide {{ $entry['type'] === 'user' ? 'bg-brand-primary/10 text-brand-primary' : 'bg-slate-100 text-slate-700' }}">
                                    {{ $entry['type'] === 'user' ? 'Usuario' : 'Contacto' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm text-brand-secondary/70">
                                No se han encontrado resultados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($results->hasPages())
        <div class="mt-2">
            {{ $results->links() }}
        </div>
    @endif
</div>
