@php
    $value = $getValue();
    $url = $getUrl();
    $tag = $url ? 'a' : 'div';
    $color = $getColor();
    $accentStyle = $color ? "border-left: 3px solid {$color};" : null;
    $description = $getDescription();
@endphp

<{!! $tag !!}
    @if ($url)
        {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab()) }}
    @endif
    {{
        $getExtraAttributeBag()
            ->class([
                'fi-wi-stats-overview-stat',
            ])
            ->style($accentStyle)
    }}
>
    <div class="fi-wi-stats-overview-stat-content">
        <div class="fi-wi-stats-overview-stat-label-ctn">
            {{ \Filament\Support\generate_icon_html($getIcon(), attributes: (new \Filament\Support\View\ComponentAttributeBag)->style($color ? "color: {$color};" : null)) }}

            <span class="fi-wi-stats-overview-stat-label">
                {{ $getLabel() }}
            </span>
        </div>

        @if (filled($value))
            <div
                class="fi-wi-stats-overview-stat-value"
                style="font-size: 2.5rem;"
                data-count-up-value="{{ $value }}"
                x-data="{
                init() {
                    const element = $el;
                    const finalText = element.dataset.countUpValue ?? element.textContent.trim();

                    if (element.dataset.countUpAnimated === 'true') {
                        return;
                    }

                    element.dataset.countUpAnimated = 'true';

                    const showFinalValue = () => {
                        element.textContent = finalText;
                        element.dataset.countUpValue = finalText;
                    };

                    if (
                        !/^\d+$/.test(finalText)
                        || window.matchMedia('(prefers-reduced-motion: reduce)').matches
                        || typeof window.requestAnimationFrame !== 'function'
                        || typeof window.performance?.now !== 'function'
                    ) {
                        showFinalValue();
                        return;
                    }

                    const finalValue = Number(finalText);

                    if (!Number.isSafeInteger(finalValue)) {
                        showFinalValue();
                        return;
                    }

                    try {
                        const visualValue = document.createElement('span');
                        const accessibleValue = document.createElement('span');

                        visualValue.setAttribute('aria-hidden', 'true');
                        visualValue.textContent = '0';
                        accessibleValue.className = 'fi-sr-only';
                        accessibleValue.textContent = finalText;

                        element.textContent = '';
                        element.append(visualValue, accessibleValue);

                        let completed = false;
                        const finish = () => {
                            if (completed) {
                                return;
                            }

                            completed = true;
                            window.clearTimeout(fallbackTimer);
                            showFinalValue();
                        };

                        const fallbackTimer = window.setTimeout(finish, 950);
                        const startedAt = window.performance.now();
                        const animate = (timestamp) => {
                            const progress = Math.min((timestamp - startedAt) / 700, 1);
                            const easedProgress = 1 - ((1 - progress) ** 3);

                            visualValue.textContent = String(Math.round(finalValue * easedProgress));

                            if (progress < 1) {
                                window.requestAnimationFrame(animate);
                                return;
                            }

                            finish();
                        };

                        window.requestAnimationFrame(animate);
                    } catch (error) {
                        showFinalValue();
                    }
                },
                }"
                x-init="init()"
            >
                {{ $value }}
            </div>
        @endif

        @if (filled($description))
            <div class="fi-wi-stats-overview-stat-description mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ $description }}
            </div>
        @endif
    </div>
</{!! $tag !!}>
