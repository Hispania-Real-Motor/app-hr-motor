@extends('layouts.app')

@section('content')
    @php
        $isLightboxOpen = false;
    @endphp

    <main
        x-data="imageLightbox()"
        x-effect="document.body.classList.toggle('overflow-hidden', isImageOpen)"
        @keydown.escape.window="closeImage()"
        @keydown.window="handleKeydown($event)"
        class="min-h-screen bg-slate-50"
    >
        <section
            class="relative isolate overflow-hidden bg-slate-900 text-white"
            style="background-image: linear-gradient(180deg, rgba(3, 7, 18, 0.72) 0%, rgba(3, 7, 18, 0.68) 100%), url('{{ asset('images/hero/hero-tablon.jpg') }}'); background-size: cover; background-position: center;"
        >
            <div class="absolute inset-0 bg-black/10"></div>
            <div class="mx-auto max-w-7xl px-6 py-10 md:px-8 md:py-14 lg:px-8 lg:py-16">
                <div class="max-w-3xl">
                    <span class="inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-white/85 backdrop-blur">
                        Comunicaci&oacute;n
                    </span>
                    <h1 class="mt-4 text-3xl font-bold tracking-tight md:text-4xl">Tabl&oacute;n de anuncios</h1>
                    <p class="mt-4 max-w-2xl text-sm leading-6 text-white/80 md:text-base">
                        Aqu&iacute; encontrar&aacute;s los avisos e informaciones publicadas para toda la plantilla. Esta secci&oacute;n es solo de lectura.
                    </p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-6 py-8 lg:px-8">
            <div class="rounded-[2rem] border border-brand-secondary/10 bg-white shadow-sm">
                <div class="p-6 md:p-8">
                    @if ($posts->isEmpty())
                        <div class="rounded-[1.75rem] border border-dashed border-brand-secondary/20 bg-slate-50 px-6 py-14 text-center">
                            <h2 class="text-2xl font-semibold text-brand-secondary">A&uacute;n no hay anuncios publicados</h2>
                            <p class="mt-2 text-sm text-brand-secondary/70">En cuanto el equipo publique algo, lo ver&aacute;s aqu&iacute;.</p>
                        </div>
                    @else
                        <div class="grid gap-5">
                            @foreach ($posts as $post)
                                @php
                                    $body = trim((string) $post->body);
                                    $isEdited = $post->updated_at && $post->created_at && $post->updated_at->gt($post->created_at);
                                @endphp

                                <article class="rounded-[1.75rem] border border-brand-secondary/10 bg-slate-50 p-5 shadow-sm md:p-6">
                                    <div class="flex flex-col gap-4">
                                        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                                            <div class="max-w-4xl">
                                                <span class="text-xs font-medium uppercase tracking-[0.18em] text-brand-secondary/45">
                                                    {{ $isEdited ? 'Editado · ' . $post->updated_at?->format('d/m/Y H:i') : $post->published_at?->format('d/m/Y H:i') }}
                                                </span>
                                                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-brand-secondary">
                                                    {{ $post->title }}
                                                </h2>
                                            </div>

                                            @if ($post->creator)
                                                <a
                                                    href="{{ route('users.show', $post->creator) }}"
                                                    class="inline-flex shrink-0 items-center gap-3 rounded-2xl border border-brand-secondary/10 bg-white px-3 py-2 transition hover:border-brand-primary/20 hover:bg-brand-primary/5 md:ml-4"
                                                >
                                                    <img
                                                        src="{{ $post->creator->avatar_url }}"
                                                        alt="Avatar de {{ $post->creator->name }}"
                                                        class="h-11 w-11 rounded-full object-cover ring-1 ring-brand-secondary/10"
                                                    >
                                                    <span class="min-w-0">
                                                        <span class="block text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/45">
                                                            Publicado por
                                                        </span>
                                                        <span class="block truncate text-sm font-semibold text-brand-secondary">
                                                            {{ $post->creator->name }}
                                                        </span>
                                                    </span>
                                                </a>
                                            @endif
                                        </div>

                                        <div class="bulletin-rich-content break-words text-sm leading-7 text-brand-secondary/80">{!! $post->rendered_body_html ?? e($body) !!}</div>

                                        @if ($post->attachments->isNotEmpty())
                                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                                @foreach ($post->attachments as $attachment)
                                                    <button
                                                        type="button"
                                                        @click="openImage({ src: @js($attachment->image_url), alt: @js($post->title), title: @js($post->title) })"
                                                        class="group relative overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white text-left shadow-sm transition hover:-translate-y-1 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                                                    >
                                                        <img
                                                            src="{{ $attachment->image_url }}"
                                                            alt="{{ $post->title }}"
                                                            class="h-56 w-full object-cover transition duration-300 group-hover:scale-105 group-hover:brightness-75"
                                                        >
                                                        <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-brand-secondary/0 text-xs font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover:bg-brand-secondary/30 group-hover:opacity-100">
                                                            Ver
                                                        </span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <div class="mt-8">
                            {{ $posts->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <div
            x-cloak
            x-show="isImageOpen"
            x-transition.opacity
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 px-6 py-8 backdrop-blur-sm"
            @click.self="closeImage()"
        >
            <div class="inline-flex max-w-[calc(100vw-3rem)] flex-col items-center">
                <div
                    x-ref="imageViewport"
                    class="relative touch-none overflow-hidden rounded-[2rem] border border-white/10 bg-white/5 shadow-2xl"
                    :class="imageScale > 1 ? (isDragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in'"
                    @wheel.prevent="handleWheel($event)"
                    @pointerdown="handlePointerDown($event)"
                    @pointermove="handlePointerMove($event)"
                    @pointerup="handlePointerUp($event)"
                    @pointercancel="handlePointerCancel($event)"
                >
                    <button
                        type="button"
                        @pointerdown.stop
                        @click.stop="closeImage()"
                        class="absolute right-3 top-3 z-10 inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Cerrar imagen ampliada"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <img
                        :src="imageUrl"
                        :alt="imageAlt"
                        @dblclick="toggleZoom($event.clientX, $event.clientY)"
                        draggable="false"
                        @dragstart.prevent
                        class="block max-h-[80vh] w-auto max-w-[calc(100vw-3rem)] select-none object-contain bg-slate-900 will-change-transform"
                        :class="isDragging ? 'transition-none' : 'transition-transform duration-200'"
                        :style="`transform: translate3d(${translateX}px, ${translateY}px, 0) scale(${imageScale}); transform-origin: center center;`"
                    >
                </div>

                <div class="mt-4 flex items-center justify-center gap-2">
                    <button
                        type="button"
                        @click="zoomOut()"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Reducir zoom"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        @click="resetZoom()"
                        class="inline-flex h-10 min-w-20 items-center justify-center rounded-full bg-white/90 px-3 text-sm font-semibold text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Restablecer zoom"
                    >
                        <span x-text="`${imageScale.toFixed(2).replace(/\.00$/, '')}x`"></span>
                    </button>
                    <button
                        type="button"
                        @click="downloadImage()"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Descargar imagen"
                    >
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-5 w-5">
                            <path d="M12.5535 16.5061C12.4114 16.6615 12.2106 16.75 12 16.75C11.7894 16.75 11.5886 16.6615 11.4465 16.5061L7.44648 12.1311C7.16698 11.8254 7.18822 11.351 7.49392 11.0715C7.79963 10.792 8.27402 10.8132 8.55352 11.1189L11.25 14.0682V3C11.25 2.58579 11.5858 2.25 12 2.25C12.4142 2.25 12.75 2.58579 12.75 3V14.0682L15.4465 11.1189C15.726 10.8132 16.2004 10.792 16.5061 11.0715C16.8118 11.351 16.833 11.8254 16.5535 12.1311L12.5535 16.5061Z" fill="#1C274C"/>
                            <path d="M3.75 15C3.75 14.5858 3.41422 14.25 3 14.25C2.58579 14.25 2.25 14.5858 2.25 15V15.0549C2.24998 16.4225 2.24996 17.5248 2.36652 18.3918C2.48754 19.2919 2.74643 20.0497 3.34835 20.6516C3.95027 21.2536 4.70814 21.5125 5.60825 21.6335C6.47522 21.75 7.57754 21.75 8.94513 21.75H15.0549C16.4225 21.75 17.5248 21.75 18.3918 21.6335C19.2919 21.5125 20.0497 21.2536 20.6517 20.6516C21.2536 20.0497 21.5125 19.2919 21.6335 18.3918C21.75 17.5248 21.75 16.4225 21.75 15.0549V15C21.75 14.5858 21.4142 14.25 21 14.25C20.5858 14.25 20.25 14.5858 20.25 15C20.25 16.4354 20.2484 17.4365 20.1469 18.1919C20.0482 18.9257 19.8678 19.3142 19.591 19.591C19.3142 19.8678 18.9257 20.0482 18.1919 20.1469C17.4365 20.2484 16.4354 20.25 15 20.25H9C7.56459 20.25 6.56347 20.2484 5.80812 20.1469C5.07435 20.0482 4.68577 19.8678 4.40901 19.591C4.13225 19.3142 3.9518 18.9257 3.85315 18.1919C3.75159 17.4365 3.75 16.4354 3.75 15Z" fill="#1C274C"/>
                        </svg>
                    </button>
                    <button
                        type="button"
                        @click="zoomIn()"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Aumentar zoom"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                </div>

                <p class="mt-4 text-center text-sm font-medium text-white/80" x-text="imageTitle"></p>
            </div>
        </div>
    </main>
@endsection
