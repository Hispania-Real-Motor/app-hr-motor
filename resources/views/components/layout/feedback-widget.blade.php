@auth
    <div
        x-data="{
            open: @js($errors->feedbackReport->any()),
            selectedType: @js(old('type', 'bug')),
            screenshotNames: [],
            init() {
                if (this.$refs.screenshotInput && this.$refs.screenshotInput.files.length > 0) {
                    this.updateScreenshots({ target: this.$refs.screenshotInput });
                }
            },
            openModal() {
                this.open = true;
                this.$nextTick(() => this.$refs.titleInput?.focus());
            },
            closeModal() {
                this.open = false;
            },
            updateScreenshots(event) {
                this.screenshotNames = Array.from(event.target.files ?? []).map((file) => file.name);
            }
        }"
        x-effect="window.bodyScrollLock?.set('feedback-modal', open)"
    >
        @php($feedbackPosition = request()->routeIs('call-audits.show') ? 'bottom-20 sm:bottom-24' : 'bottom-5 sm:bottom-7')
        <button
            type="button"
            @click="openModal()"
            class="fixed {{ $feedbackPosition }} right-5 z-[55] inline-flex h-12 w-12 cursor-pointer items-center justify-center rounded-full bg-brand-primary text-white shadow-[0_18px_40px_rgba(15,23,42,0.28)] transition hover:-translate-y-0.5 hover:bg-brand-primary focus:outline-none sm:right-7 sm:h-12 sm:w-12"
            aria-label="Reportar un bug o enviar una sugerencia"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12,1A11,11,0,1,0,23,12,11.013,11.013,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9.011,9.011,0,0,1,12,21Zm1-4.5v2H11v-2Zm3-7a3.984,3.984,0,0,1-1.5,3.122A3.862,3.862,0,0,0,13.063,15H11.031a5.813,5.813,0,0,1,2.219-3.936A2,2,0,0,0,13.1,7.832a2.057,2.057,0,0,0-2-.14A1.939,1.939,0,0,0,10,9.5,1,1,0,0,1,8,9.5V9.5a3.909,3.909,0,0,1,2.319-3.647,4.061,4.061,0,0,1,3.889.315A4,4,0,0,1,16,9.5Z" />
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/60 p-0 sm:items-center sm:p-6"
            @keydown.escape.window="closeModal()"
            @click.self="closeModal()"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="feedback-widget-title"
                class="relative w-full max-w-2xl overflow-hidden rounded-t-[2rem] bg-white shadow-2xl sm:max-h-[calc(100dvh-3rem)] sm:rounded-[2rem]"
            >
                <div class="border-b border-slate-200 bg-brand-secondary px-6 py-5 text-white">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/75">
                                Soporte interno
                            </p>
                            <h2 id="feedback-widget-title" class="mt-1 text-xl font-semibold">
                                Reportar bug o sugerencia
                            </h2>
                        </div>

                        <button type="button" @click="closeModal()" class="cursor-pointer rounded-full p-2 text-white/80 transition hover:bg-white/10 hover:text-white" aria-label="Cerrar formulario">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <p class="mt-2 max-w-xl text-sm leading-6 text-white/85">
                        Cuéntanos qué has visto o qué mejorarías. Si puedes, adjunta una o varias capturas para localizarlo antes.
                    </p>
                </div>

                <form method="POST" action="{{ route('feedback.store') }}" enctype="multipart/form-data" class="max-h-[calc(100dvh-12rem)] overflow-y-auto" data-feedback-loader-form>
                    @csrf
                    <input type="hidden" name="page_url" value="{{ request()->fullUrl() }}">
                    <input type="hidden" name="page_title" value="{{ trim($__env->yieldContent('title', config('app.name', 'App HR Motor'))) }}">

                    <div class="grid gap-5 px-6 py-6">
                        <div>
                            <label class="text-sm font-semibold text-brand-secondary">
                                Tipo
                            </label>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <label
                                    @click="selectedType = 'bug'"
                                    :class="selectedType === 'bug'
                                        ? 'border-brand-primary bg-brand-primary/5 ring-1 ring-brand-primary/15'
                                        : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="cursor-pointer rounded-2xl border p-4 transition"
                                >
                                    <input type="radio" name="type" value="bug" class="sr-only" x-model="selectedType">
                                    <div class="flex items-start gap-3">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-50 text-xl">🐛</span>
                                        <div>
                                            <p class="font-semibold text-brand-secondary">Bug</p>
                                            <p class="mt-1 text-sm leading-6 text-brand-secondary/70">Algo no funciona como debería.</p>
                                        </div>
                                    </div>
                                </label>

                                <label
                                    @click="selectedType = 'suggestion'"
                                    :class="selectedType === 'suggestion'
                                        ? 'border-brand-primary bg-brand-primary/5 ring-1 ring-brand-primary/15'
                                        : 'border-slate-200 bg-white hover:border-slate-300'"
                                    class="cursor-pointer rounded-2xl border p-4 transition"
                                >
                                    <input type="radio" name="type" value="suggestion" class="sr-only" x-model="selectedType">
                                    <div class="flex items-start gap-3">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-50 text-xl">💡</span>
                                        <div>
                                            <p class="font-semibold text-brand-secondary">Sugerencia</p>
                                            <p class="mt-1 text-sm leading-6 text-brand-secondary/70">Una mejora, ajuste o idea nueva.</p>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            @error('type', 'feedbackReport')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="feedback-title" class="text-sm font-semibold text-brand-secondary">
                                Título
                            </label>
                            <input
                                id="feedback-title"
                                x-ref="titleInput"
                                type="text"
                                name="title"
                                value="{{ old('title') }}"
                                maxlength="120"
                                placeholder="Por ejemplo: No carga el listado de usuarios"
                                class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-brand-secondary outline-none transition placeholder:text-slate-400 focus:border-brand-primary focus:ring-4 focus:ring-brand-primary/10"
                            >
                            @error('title', 'feedbackReport')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="feedback-description" class="text-sm font-semibold text-brand-secondary">
                                Descripción
                            </label>
                            <textarea
                                id="feedback-description"
                                name="description"
                                rows="6"
                                placeholder="Explícanos qué ha pasado, qué esperabas ver y cualquier detalle que nos ayude."
                                class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-brand-secondary outline-none transition placeholder:text-slate-400 focus:border-brand-primary focus:ring-4 focus:ring-brand-primary/10"
                            >{{ old('description') }}</textarea>
                            @error('description', 'feedbackReport')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="feedback-screenshots" class="text-sm font-semibold text-brand-secondary">
                                Capturas de pantalla
                            </label>
                            <input
                                id="feedback-screenshots"
                                x-ref="screenshotInput"
                                type="file"
                                name="screenshots[]"
                                accept="image/*"
                                multiple
                                @change="updateScreenshots($event)"
                                class="mt-2 block w-full rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-full file:border-0 file:bg-brand-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:border-slate-400"
                            >
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                Hasta 3 imágenes, 5 MB cada una.
                            </p>

                            @error('screenshots', 'feedbackReport')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            @error('screenshots.*', 'feedbackReport')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            <div x-show="screenshotNames.length > 0" x-cloak class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Archivos seleccionados</p>
                                <ul class="mt-2 space-y-1 text-sm text-slate-700">
                                    <template x-for="name in screenshotNames" :key="name">
                                        <li class="truncate" x-text="name"></li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            @click="closeModal()"
                            class="inline-flex cursor-pointer items-center justify-center rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            data-feedback-loader-button
                            data-feedback-loader-default="Enviar reporte"
                            data-feedback-loader-loading="Enviando reporte..."
                            class="inline-flex cursor-pointer items-center justify-center rounded-2xl bg-brand-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-primary/95"
                        >
                            <span data-feedback-loader-label>Enviar reporte</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>

        @if (session('feedback_report_success'))
            <div
                x-data="{ open: true }"
                x-show="open"
                x-cloak
                class="fixed bottom-24 right-5 z-[70] max-w-sm rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 pr-10 text-sm font-medium text-emerald-700 shadow-lg"
            >
                <button
                    type="button"
                    @click="open = false"
                    class="absolute right-2 top-2 inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full text-emerald-500 transition hover:bg-emerald-100 hover:text-emerald-700"
                    aria-label="Cerrar aviso de éxito"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>

                {{ session('feedback_report_success') }}
            </div>
        @endif

        @if (session('feedback_report_error'))
            <div class="fixed bottom-24 right-5 z-[70] max-w-sm rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 shadow-lg">
                {{ session('feedback_report_error') }}
            </div>
        @endif
    </div>
@endauth

@auth
    <div
        id="feedback-report-loader"
        class="pointer-events-none fixed inset-0 z-[80] hidden items-center justify-center bg-slate-950/45 px-6 py-8 opacity-0 backdrop-blur-sm transition-opacity duration-200"
    >
        <div class="w-full max-w-md rounded-[2rem] border border-white/60 bg-white/95 p-7 text-center shadow-[0_30px_80px_rgba(15,23,42,0.22)]">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[radial-gradient(circle_at_top,rgba(239,68,68,0.18),rgba(255,255,255,0.95))] ring-1 ring-brand-primary/10">
                <div class="h-8 w-8 animate-spin rounded-full border-[3px] border-brand-primary/20 border-t-brand-primary"></div>
            </div>
            <h2 class="mt-5 text-xl font-semibold text-brand-secondary">Enviando reporte</h2>
            <p class="mt-2 text-sm leading-6 text-brand-secondary/70">
                Estamos enviando tu mensaje y las capturas adjuntas. Esta pantalla se cerrará sola al terminar.
            </p>
        </div>
    </div>

    <script>
        (() => {
            const overlay = document.getElementById('feedback-report-loader');

            document.querySelectorAll('[data-feedback-loader-form]').forEach((form) => {
                let submitted = false;

                form.addEventListener('submit', (event) => {
                    if (submitted) {
                        return;
                    }

                    submitted = true;
                    event.preventDefault();

                    const button = form.querySelector('[data-feedback-loader-button]');
                    const label = form.querySelector('[data-feedback-loader-label]');
                    if (button) {
                        button.disabled = true;
                        button.classList.add('opacity-90');
                    }

                    if (overlay) {
                        overlay.classList.remove('hidden');

                        requestAnimationFrame(() => {
                            overlay.classList.remove('pointer-events-none', 'opacity-0');
                            overlay.classList.add('flex', 'opacity-100');
                        });
                    }

                    if (label && button?.dataset.feedbackLoaderLoading) {
                        label.textContent = button.dataset.feedbackLoaderLoading;
                    }

                    window.setTimeout(() => form.submit(), 80);
                });
            });
        })();
    </script>
@endauth
