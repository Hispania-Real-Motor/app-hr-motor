@extends('layouts.app')

@section('content')
    @php
        $totalOpenTickets = collect($reportCards)->sum('totalOpenTickets');
        $peopleWithOpenTickets = collect($reportCards)->count();
        $topCard = collect($reportCards)->sortByDesc('totalOpenTickets')->first();
        $openTicketsHistorySeries = collect(data_get($openTicketsHistoryReport ?? [], 'series', []));
        $openTicketsHistoryHasData = $openTicketsHistorySeries->contains(fn (array $point): bool => (int) ($point['count'] ?? 0) > 0);
        $openTicketsChartWidth = 760;
        $openTicketsChartHeight = 300;
        $openTicketsChartPadding = [
            'top' => 20,
            'right' => 18,
            'bottom' => 54,
            'left' => 48,
        ];
        $openTicketsPlotWidth = $openTicketsChartWidth - $openTicketsChartPadding['left'] - $openTicketsChartPadding['right'];
        $openTicketsPlotHeight = $openTicketsChartHeight - $openTicketsChartPadding['top'] - $openTicketsChartPadding['bottom'];
        $openTicketsMaxCount = max(1, (int) $openTicketsHistorySeries->max('count'));
        $openTicketsChartPoints = $openTicketsHistorySeries->values()->map(function (array $point, int $index) use ($openTicketsHistorySeries, $openTicketsChartPadding, $openTicketsPlotWidth, $openTicketsPlotHeight, $openTicketsMaxCount): array {
            $count = max(1, $openTicketsHistorySeries->count());
            $x = $openTicketsChartPadding['left'] + ($count === 1 ? $openTicketsPlotWidth / 2 : ($openTicketsPlotWidth * $index) / ($count - 1));
            $value = (int) ($point['count'] ?? 0);
            $normalized = $value / $openTicketsMaxCount;
            $y = $openTicketsChartPadding['top'] + ($openTicketsPlotHeight - ($openTicketsPlotHeight * $normalized));
            $labelY = $y <= $openTicketsChartPadding['top'] + 26 ? $y + 22 : max(16, $y - 14);

            return $point + [
                'x' => $x,
                'y' => $y,
                'label_x' => $x,
                'label_anchor' => 'middle',
                'label_y' => $labelY,
            ];
        });
        $openTicketsLinePoints = $openTicketsChartPoints->map(fn (array $point): string => number_format((float) $point['x'], 2, '.', '') . ',' . number_format((float) $point['y'], 2, '.', ''))->implode(' ');
        $openTicketsGridValues = collect(range(0, 5))
            ->map(fn (int $step): int => (int) round(($openTicketsMaxCount * $step) / 5))
            ->unique()
            ->sortDesc()
            ->values();
        $ticketToolReportRows = collect(data_get($ticketToolReport ?? [], 'rows', []));
        $ticketToolReportTotal = (int) data_get($ticketToolReport, 'totalTickets', 0);
        $ticketToolRange = (string) ($ticketToolRange ?? 'all');
        $ticketToolRangeOptions = collect($ticketToolRangeOptions ?? []);
        $closedUsersRange = (string) ($closedUsersRange ?? 'all');
        $closedUsersRangeOptions = collect($closedUsersRangeOptions ?? []);
        $resolutionRange = (string) ($resolutionRange ?? 'all');
        $resolutionRangeOptions = collect($resolutionRangeOptions ?? []);
        $resolutionRows = collect(data_get($resolutionReport ?? [], 'rows', []));
        $resolutionChartRows = $resolutionRows->sortBy('averageMinutes')->values();
        $resolutionMaxMinutes = (int) ($resolutionRows->max('averageMinutes') ?? 0);
        $resolutionOverallAverageMinutes = (int) data_get($resolutionReport, 'overallAverageMinutes', 0);
        $dealershipRange = (string) ($dealershipRange ?? 'all');
        $dealershipRangeOptions = collect($dealershipRangeOptions ?? []);
        $dealershipReportRows = collect(data_get($dealershipReport ?? [], 'rows', []));
        $dealershipReportTotal = (int) data_get($dealershipReport, 'totalTickets', 0);
        $ticketsByRequesterRows = collect(data_get($ticketsByRequesterReport ?? [], 'rows', []));
        $polarToCartesian = function (float $centerX, float $centerY, float $radius, float $angleDeg): array {
            $angleRad = deg2rad($angleDeg - 90);

            return [
                'x' => $centerX + ($radius * cos($angleRad)),
                'y' => $centerY + ($radius * sin($angleRad)),
            ];
        };
        $describePieSlice = function (float $centerX, float $centerY, float $radius, float $startAngle, float $endAngle) use ($polarToCartesian): string {
            $sweep = $endAngle - $startAngle;
            $outerStart = $polarToCartesian($centerX, $centerY, $radius, $startAngle);

            if ($sweep >= 359.999) {
                $midPoint = $polarToCartesian($centerX, $centerY, $radius, $startAngle + 180);

                return sprintf(
                    'M %.3f %.3f L %.3f %.3f A %.3f %.3f 0 1 1 %.3f %.3f A %.3f %.3f 0 1 1 %.3f %.3f Z',
                    $centerX,
                    $centerY,
                    $outerStart['x'],
                    $outerStart['y'],
                    $radius,
                    $radius,
                    $midPoint['x'],
                    $midPoint['y'],
                    $radius,
                    $radius,
                    $outerStart['x'],
                    $outerStart['y']
                );
            }

            $outerEnd = $polarToCartesian($centerX, $centerY, $radius, $endAngle);
            $largeArc = ($sweep > 180) ? 1 : 0;

            return sprintf(
                'M %.3f %.3f L %.3f %.3f A %.3f %.3f 0 %d 1 %.3f %.3f Z',
                $centerX,
                $centerY,
                $outerStart['x'],
                $outerStart['y'],
                $radius,
                $radius,
                $largeArc,
                $outerEnd['x'],
                $outerEnd['y']
            );
        };
        $contrastTextColor = function (string $hexColor): string {
            $hex = ltrim($hexColor, '#');

            if (strlen($hex) === 3) {
                $hex = preg_replace('/(.)/u', '$1$1', $hex);
            }

            if (! is_string($hex) || strlen($hex) !== 6) {
                return '#ffffff';
            }

            $red = hexdec(substr($hex, 0, 2));
            $green = hexdec(substr($hex, 2, 2));
            $blue = hexdec(substr($hex, 4, 2));
            $luminance = (0.299 * $red) + (0.587 * $green) + (0.114 * $blue);

            return $luminance > 170 ? '#0f172a' : '#ffffff';
        };
        $segmentLabelPosition = function (float $centerX, float $centerY, float $innerRadius, float $outerRadius, float $startAngle, float $endAngle) use ($polarToCartesian): array {
            $midAngle = ($startAngle + $endAngle) / 2;
            $labelRadius = $innerRadius + (($outerRadius - $innerRadius) * 0.62);

            return $polarToCartesian($centerX, $centerY, $labelRadius, $midAngle);
        };
    @endphp

    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-8 lg:px-8">
        <section class="overflow-hidden rounded-[2rem] border border-brand-secondary/10 bg-white shadow-sm">
            <div
                class="relative bg-cover bg-no-repeat px-6 py-10 sm:px-8 sm:py-12"
                style="background-image: url('{{ $heroImageUrl }}'); background-position: center 50%;"
            >
                <div class="absolute inset-0 bg-slate-950/70"></div>
                <div class="relative flex flex-col gap-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="space-y-3">
                            <div class="inline-flex items-center rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-white backdrop-blur-sm">
                                Tickets IT
                            </div>
                            <div class="space-y-2">
                                <h1 class="text-3xl font-bold tracking-tight text-white sm:text-5xl">
                                    Informes de ticketing
                                </h1>
                                <p class="max-w-3xl text-sm leading-6 text-white/80 sm:text-base">
                                    Este primer informe muestra las incidencias actuales sin resolver, agrupadas por cada persona de IT y coloreadas según su estado.
                                </p>
                            </div>
                        </div>

                        <a href="{{ $backUrl }}" class="inline-flex items-center justify-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-brand-secondary shadow-sm transition hover:-translate-y-0.5">
                            Volver a tickets
                        </a>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-[1.5rem] border border-white/15 bg-white/10 p-4 text-white backdrop-blur-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/65">Total abiertos</p>
                            <p class="mt-2 text-3xl font-bold">{{ $totalOpenTickets }}</p>
                        </div>

                        <div class="rounded-[1.5rem] border border-white/15 bg-white/10 p-4 text-white backdrop-blur-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/65">Personas con carga</p>
                            <p class="mt-2 text-3xl font-bold">{{ $peopleWithOpenTickets }}</p>
                        </div>

                        <div class="rounded-[1.5rem] border border-white/15 bg-white/10 p-4 text-white backdrop-blur-sm">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/65">Más cargada</p>
                            <p class="mt-2 text-lg font-bold leading-tight">
                                {{ $topCard['name'] ?? 'Sin datos' }}
                            </p>
                            <p class="mt-1 text-sm text-white/75">
                                {{ $topCard['totalOpenTickets'] ?? 0 }} incidencias abiertas
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>


        @if (empty($reportCards))
    <section class="mt-6 rounded-[2rem] border border-dashed border-brand-secondary/15 bg-white p-10 text-center shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-secondary/45">Sin incidencias</p>
        <h2 class="mt-3 text-2xl font-bold tracking-tight text-brand-secondary">No hay tickets abiertos para mostrar</h2>
        <p class="mt-3 text-sm leading-6 text-brand-secondary/65">Cuando haya incidencias sin resolver asignadas a IT, aparecerán aquí repartidas por persona y por estado.</p>
    </section>
@else
    <section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
        <h2 class="text-2xl font-bold tracking-tight text-brand-secondary">Tickets abiertos por usuario</h2>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="mt-4 text-xs font-semibold uppercase tracking-[0.2em] text-brand-secondary/45">Leyenda</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($openStatusOrder as $statusKey)
                        @php $status = $openStatusMeta[$statusKey] ?? null; @endphp
                        @if ($status)
                            <span class="inline-flex items-center gap-2 rounded-full border border-brand-secondary/10 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-brand-secondary/70">
                                <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $status["badgeColor"] ?? "#94a3b8" }}"></span>
                                {{ $status["label"] }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
            <div class="max-w-xl text-sm leading-6 text-brand-secondary/65">Solo se incluyen tickets en curso, pendientes de usuario y reaperturas. Los cerrados y clausurados quedan fuera de este informe.</div>
        </div>
        <div class="mt-6 grid gap-4 xl:grid-cols-6">
            @foreach ($reportCards as $card)
                @php
                    $sliceStart = 0.0;
                    $chartSegments = collect($card["segments"])->map(function ($segment) use (&$sliceStart) {
                        $start = $sliceStart;
                        $end = $sliceStart + ($segment["percentage"] * 3.6);
                        $sliceStart = $end;
                        return [
                            "key" => $segment["key"],
                            "label" => $segment["label"],
                            "count" => $segment["count"],
                            "color" => $segment["color"],
                            "start" => $start,
                            "end" => $end,
                        ];
                    })->all();
                    $centerX = 140;
                    $centerY = 140;
                    $outerRadius = 122;
                    $innerRadius = 0;
                    $singleSegment = count($chartSegments) === 1;
                @endphp
                <article class="rounded-[2rem] bg-white p-4">
                    <div class="flex flex-col items-center gap-4">
                        <div class="relative w-full max-w-[11rem] shrink-0">
                            <svg viewBox="0 0 280 280" class="h-auto w-full" role="img" aria-label="Rosco de incidencias de {{ $card["name"] }}">
                                @foreach ($chartSegments as $segment)
                                    @php
                                        $slicePath = $describePieSlice($centerX, $centerY, $outerRadius, $segment["start"], $segment["end"]);
                                        $labelPosition = $segment["count"] > 0 ? $segmentLabelPosition($centerX, $centerY, $innerRadius, $outerRadius, $segment["start"], $segment["end"]) : ["x" => $centerX, "y" => $centerY];
                                        $labelColor = $contrastTextColor($segment["color"]);
                                        $strokeColor = $singleSegment ? "none" : "#ffffff";
                                        $strokeWidth = $singleSegment ? "0" : "5";
                                        $segmentLink = route('tickets.index', [
                                            'managed_search' => $card['name'],
                                            'managed_status' => $segment['key'],
                                        ]);
                                    @endphp
                                    <a href="{{ $segmentLink }}" class="group">
                                        <path
                                            d="{{ $slicePath }}"
                                            fill="{{ $segment["color"] }}"
                                            stroke="{{ $strokeColor }}"
                                            stroke-width="{{ $strokeWidth }}"
                                            stroke-linejoin="round"
                                            class="cursor-pointer transition duration-150 ease-out group-hover:opacity-90 group-hover:scale-[1.02]"
                                            style="transform-box: fill-box; transform-origin: center;"
                                        />
                                        @if (! $singleSegment)
                                            <text x="{{ $labelPosition["x"] }}" y="{{ $labelPosition["y"] }}" fill="{{ $labelColor }}" text-anchor="middle" dominant-baseline="middle" font-size="30" font-weight="900" style="paint-order: stroke; stroke: rgba(15, 23, 42, 0.18); stroke-width: 3px;">{{ $segment["count"] }}</text>
                                        @endif
                                    </a>
                                @endforeach
                                @if ($singleSegment)
                                    <text x="{{ $centerX }}" y="{{ $centerY }}" fill="{{ $contrastTextColor($chartSegments[0]["color"]) }}" text-anchor="middle" dominant-baseline="middle" font-size="46" font-weight="900" style="paint-order: stroke; stroke: rgba(15, 23, 42, 0.18); stroke-width: 3px;">{{ $chartSegments[0]["count"] }}</text>
                                @endif
                            </svg>
                        </div>
                        <div class="text-center">
                            <h2 class="text-lg font-bold tracking-tight text-brand-secondary">{{ $card["name"] }}</h2>
                            <p class="mt-1 text-xs text-brand-secondary/65">{{ $card["totalOpenTickets"] }} incidencias abiertas</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif

<section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
    <div
        data-closed-users-report
        data-closed-users-report-url="{{ route('tickets.reports') }}"
        data-closed-users-range="{{ $closedUsersRange }}"
    >
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-brand-secondary">Tickets cerrados por usuario</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-secondary/65">
                    El filtro temporal se actualiza al instante y cambia esta gráfica sin recargar la página.
                </p>
            </div>

            <div class="inline-flex w-fit max-w-full">
                <select
                    id="closed-users-range"
                    data-closed-users-range-select
                    class="w-auto min-w-[13rem] max-w-full rounded-2xl border border-brand-secondary/10 bg-white px-4 py-3 text-sm font-semibold text-brand-secondary shadow-sm outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/15"
                    style="width: max-content;"
                >
                    @foreach ($closedUsersRangeOptions as $rangeKey => $rangeOption)
                        <option value="{{ $rangeKey }}" @selected($closedUsersRange === $rangeKey)>{{ $rangeOption['label'] ?? $rangeKey }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-6 transition-[opacity,transform] duration-300 ease-out" data-closed-users-report-body>
            @include('tickets.partials.closed-users-report-body', [
                'closedReportRows' => $closedReportRows,
            ])
        </div>
    </div>
</section>

<section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
    <h2 class="text-2xl font-bold tracking-tight text-brand-secondary">Tiempo medio de resolución</h2>

    <div class="mt-5 grid gap-3 sm:grid-cols-2">
        <div class="rounded-[1.5rem] bg-slate-50 px-4 py-4"><p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/45">Media total</p><p class="mt-2 text-3xl font-bold text-brand-secondary">{{ data_get($resolutionReport, "overallAverageLabel", "0 min") }}</p></div>
        <div class="rounded-[1.5rem] bg-slate-50 px-4 py-4"><p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/45">Tickets medidos</p><p class="mt-2 text-3xl font-bold text-brand-secondary">{{ data_get($resolutionReport, "totalResolvedTickets", 0) }}</p></div>
    </div>

    @if ($resolutionRows->isEmpty())
        <div class="mt-6 rounded-[1.5rem] bg-slate-50 px-4 py-4 text-sm text-brand-secondary/65">No hay tickets cerrados o clausurados suficientes para calcular el tiempo medio.</div>
    @else
        @php
            $resolutionChartWidth = max(1260, count($resolutionChartRows) * 190);
            $resolutionChartHeight = 290;
            $resolutionAxisY = 220;
            $resolutionMaxBarHeight = 145;
            $resolutionChartLeft = 70;
            $resolutionChartRight = 70;
            $resolutionChartInnerWidth = $resolutionChartWidth - $resolutionChartLeft - $resolutionChartRight;
            $resolutionAverageColor = '#22c55e';
            $resolutionOverColor = '#E51A2E';
        @endphp

        <div class="mt-6 overflow-x-auto">
            <svg
                viewBox="0 0 {{ $resolutionChartWidth }} {{ $resolutionChartHeight }}"
                class="mx-auto block h-auto"
                style="width: {{ $resolutionChartWidth }}px; max-width: 100%;"
                role="img"
                aria-label="Gráfica de tiempo medio de resolución por usuario"
            >
                <line x1="{{ $resolutionChartLeft }}" y1="{{ $resolutionAxisY }}" x2="{{ $resolutionChartWidth - $resolutionChartRight }}" y2="{{ $resolutionAxisY }}" stroke="#cbd5e1" stroke-width="2" />

                @if ($resolutionOverallAverageMinutes > 0)
                    @php
                        $overallLineY = $resolutionAxisY - (($resolutionOverallAverageMinutes / max($resolutionMaxMinutes, 1)) * $resolutionMaxBarHeight);
                        $overallLineY = max(36, min($resolutionAxisY - 4, $overallLineY));
                    @endphp
                    <line x1="{{ $resolutionChartLeft }}" y1="{{ $overallLineY }}" x2="{{ $resolutionChartWidth - $resolutionChartRight }}" y2="{{ $overallLineY }}" stroke="#94a3b8" stroke-dasharray="6 6" stroke-width="2" />
                    <text x="{{ $resolutionChartWidth - 22 }}" y="{{ $overallLineY - 8 }}" text-anchor="end" fill="#475569" font-size="14" font-weight="700">Media total</text>
                @endif

                @foreach ($resolutionChartRows as $index => $row)
                    @php
                        $slotWidth = $resolutionChartInnerWidth / max(count($resolutionChartRows), 1);
                        $barWidth = min(62, max(30, $slotWidth * 0.38));
                        $barX = $resolutionChartLeft + ($slotWidth * $index) + (($slotWidth - $barWidth) / 2);
                        $barHeight = $resolutionMaxMinutes > 0 ? (($row["averageMinutes"] / $resolutionMaxMinutes) * $resolutionMaxBarHeight) : 0;
                        $barTop = $resolutionAxisY - $barHeight;
                        $labelX = $barX + ($barWidth / 2);
                        $barRadius = min(8, $barHeight / 2);
                        $barColor = $row["averageMinutes"] <= $resolutionOverallAverageMinutes ? $resolutionAverageColor : $resolutionOverColor;
                    @endphp
                    <g>
                        @if ($barHeight > 0)
                            <path d="M {{ $barX }} {{ $resolutionAxisY }} H {{ $barX + $barWidth }} V {{ $barTop + $barRadius }} A {{ $barRadius }} {{ $barRadius }} 0 0 0 {{ $barX + $barWidth - $barRadius }} {{ $barTop }} H {{ $barX + $barRadius }} A {{ $barRadius }} {{ $barRadius }} 0 0 0 {{ $barX }} {{ $barTop + $barRadius }} Z" fill="{{ $barColor }}" />
                        @endif

                        <text x="{{ $labelX }}" y="{{ max(36, $barTop - 8) }}" text-anchor="middle" fill="#0f172a" font-size="22" font-weight="800">{{ $row["averageLabel"] }}</text>
                        <text x="{{ $labelX }}" y="{{ $resolutionAxisY + 30 }}" text-anchor="middle" fill="#334155" font-size="16" font-weight="700">{{ $row["name"] }}</text>
                    </g>
                @endforeach
            </svg>
        </div>
    @endif
    </div>
</section>
<section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
    <div
        data-ticket-tool-report
        data-ticket-tool-report-url="{{ route('tickets.reports') }}"
        data-ticket-tool-range="{{ $ticketToolRange }}"
    >
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-brand-secondary">Tickets por tipo de incidencia</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-secondary/65">
                Este gráfico agrupa todos los tickets, sin importar su estado o la persona asignada. Pasa el cursor sobre cada segmento para ver a qué herramienta corresponde.
            </p>
        </div>
        <div class="rounded-[1.25rem] bg-slate-50 px-4 py-3 text-sm font-semibold text-brand-secondary/70">
            {{ $ticketToolReportTotal }} incidencias en total
        </div>
            <div class="inline-flex w-fit max-w-full">
                <select
                    id="ticket-tool-range"
                    data-ticket-tool-range-select
                    class="w-auto min-w-[13rem] max-w-full rounded-2xl border border-brand-secondary/10 bg-white px-4 py-3 text-sm font-semibold text-brand-secondary shadow-sm outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/15"
                    style="width: max-content;"
                >
                @foreach ($ticketToolRangeOptions as $rangeKey => $rangeOption)
                    <option value="{{ $rangeKey }}" @selected($ticketToolRange === $rangeKey)>{{ $rangeOption['label'] ?? $rangeKey }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-6 transition-[opacity,transform] duration-300 ease-out" data-ticket-tool-report-body>
    @if ($ticketToolReportRows->isEmpty())
        <div class="mt-6 rounded-[1.5rem] bg-slate-50 px-4 py-4 text-sm text-brand-secondary/65">
            No hay tickets suficientes para mostrar el informe por tipo de incidencia.
        </div>
    @else
        @php
            $ticketToolSegments = [];
            $ticketToolSliceStart = 0.0;
            $ticketToolTotal = max($ticketToolReportRows->sum('total'), 1);
        @endphp

        <div
            class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between"
            x-data="{
                ticketToolTooltip: null,
                ticketToolTooltipVisible: false,
                ticketToolTooltipTimer: null,
                scheduleTicketToolTooltip(payload) {
                    clearTimeout(this.ticketToolTooltipTimer);
                    this.ticketToolTooltipTimer = setTimeout(() => {
                        this.ticketToolTooltip = payload;
                        this.ticketToolTooltipVisible = true;
                    }, 220);
                },
                hideTicketToolTooltip() {
                    clearTimeout(this.ticketToolTooltipTimer);
                    this.ticketToolTooltipTimer = null;
                    this.ticketToolTooltipVisible = false;
                    window.setTimeout(() => {
                        if (!this.ticketToolTooltipVisible) {
                            this.ticketToolTooltip = null;
                        }
                    }, 160);
                },
            }"
        >
            <div class="relative mx-auto w-full max-w-[20rem] shrink-0">
                <svg viewBox="0 0 280 280" class="h-auto w-full" role="img" aria-label="Gráfica de tickets por tipo de incidencia">
                    @foreach ($ticketToolReportRows as $row)
                        @php
                            $percentage = ($row['total'] / $ticketToolTotal) * 100;
                            $ticketToolSegments[] = [
                                'name' => $row['name'],
                                'total' => $row['total'],
                                'color' => $row['color'],
                                'start' => $ticketToolSliceStart,
                                'end' => $ticketToolSliceStart + ($percentage * 3.6),
                            ];
                            $ticketToolSliceStart += ($percentage * 3.6);
                        @endphp
                    @endforeach

                    @foreach ($ticketToolSegments as $segment)
                        @php
                            $slicePath = $describePieSlice(140, 140, 122, $segment['start'], $segment['end']);
                            $isSingleSegment = count($ticketToolSegments) === 1;
                            $strokeColor = $isSingleSegment ? 'none' : '#ffffff';
                            $strokeWidth = $isSingleSegment ? '0' : '1.25';
                        @endphp
                        <path
                            d="{{ $slicePath }}"
                            fill="{{ $segment['color'] }}"
                            stroke="{{ $strokeColor }}"
                            stroke-width="{{ $strokeWidth }}"
                            stroke-linejoin="round"
                            class="cursor-help"
                            @mouseenter="scheduleTicketToolTooltip({ name: @js($segment['name']), total: {{ $segment['total'] }} })"
                            @mousemove="hideTicketToolTooltip(); scheduleTicketToolTooltip({ name: @js($segment['name']), total: {{ $segment['total'] }} })"
                            @mouseleave="hideTicketToolTooltip()"
                        />
                    @endforeach
                </svg>

                <div
                    x-cloak
                    x-show="ticketToolTooltipVisible"
                    x-transition.opacity.duration.150ms
                    class="pointer-events-none absolute left-1/2 top-full z-50 mt-3 w-max max-w-[18rem] -translate-x-1/2 rounded-xl bg-brand-secondary px-3 py-2 text-xs font-semibold text-white shadow-lg"
                >
                    <div class="whitespace-nowrap" x-text="ticketToolTooltip?.name"></div>
                    <div class="mt-0.5 text-white/75" x-text="ticketToolTooltip ? `${ticketToolTooltip.total} ${ticketToolTooltip.total === 1 ? 'incidencia' : 'incidencias'}` : ''"></div>
                </div>
            </div>

            <div class="w-full max-w-[32rem] shrink-0 space-y-3 lg:ml-auto">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-secondary/45">Leyenda</p>
                <div class="grid grid-cols-2 gap-x-10 gap-y-2 lg:grid-cols-3">
                    @foreach ($ticketToolReportRows as $row)
                        <div class="flex items-center gap-3 py-1.5">
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <span class="h-3.5 w-3.5 shrink-0 rounded-full" style="background-color: {{ $row['color'] }}"></span>
                                <span class="truncate text-sm font-medium leading-tight text-brand-secondary">{{ $row['name'] }}</span>
                            </div>
                            <span class="shrink-0 text-sm font-semibold text-brand-secondary/65">{{ $row['total'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
    </div>
</div>
</div>
</section>

<section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6" data-ticket-open-history-report>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-secondary/45">Últimos 30 días</p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-brand-secondary">Incidencias creadas por día</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-secondary/65">
                Esta gráfica muestra cuántas incidencias se han creado cada día en los últimos 30 días.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[18rem] lg:grid-cols-1">
            <div class="rounded-[1.35rem] border border-brand-secondary/10 bg-slate-50 p-4" data-ticket-open-history-total data-ticket-open-history-total-value="{{ (int) data_get($openTicketsHistoryReport, 'totalTickets', 0) }}">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/45">Total en 30 días</p>
                <p class="mt-2 text-3xl font-bold text-brand-secondary">{{ number_format((int) data_get($openTicketsHistoryReport, 'totalTickets', 0)) }}</p>
            </div>
            <div class="rounded-[1.35rem] border border-brand-secondary/10 bg-slate-50 p-4" data-ticket-open-history-peak data-ticket-open-history-peak-value="{{ (int) data_get($openTicketsHistoryReport, 'peakDayCount', 0) }}">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/45">Día con más incidencias</p>
                <p class="mt-2 text-3xl font-bold text-brand-secondary">{{ number_format((int) data_get($openTicketsHistoryReport, 'peakDayCount', 0)) }}</p>
                <p class="mt-1 text-sm text-brand-secondary/65" data-ticket-open-history-peak-label>{{ data_get($openTicketsHistoryReport, 'peakDayLabel', 'Sin datos') }}</p>
            </div>
        </div>
    </div>

    @if ($openTicketsHistoryHasData)
        <div class="mt-6 overflow-hidden rounded-[1.5rem] border border-gray-100 bg-gradient-to-b from-gray-50 to-white p-4 sm:p-5">
            <svg viewBox="0 0 {{ $openTicketsChartWidth }} {{ $openTicketsChartHeight }}" class="h-auto w-full" role="img" aria-label="Evolución diaria de incidencias creadas">
                <title>Evolución diaria de incidencias creadas</title>
                <desc>Gráfica de líneas con el número de incidencias creadas cada día en los últimos 30 días.</desc>

                @foreach ($openTicketsGridValues as $gridValue)
                    @php
                        $gridY = $openTicketsChartPadding['top'] + ($openTicketsPlotHeight - ($openTicketsPlotHeight * ($gridValue / $openTicketsMaxCount)));
                    @endphp
                    <line x1="{{ $openTicketsChartPadding['left'] }}" y1="{{ $gridY }}" x2="{{ $openTicketsChartWidth - $openTicketsChartPadding['right'] }}" y2="{{ $gridY }}" stroke="#e5e7eb" stroke-dasharray="4 5" stroke-width="1" />
                    <text x="16" y="{{ $gridY + 4 }}" fill="#6b7280" font-size="12" font-weight="600">{{ $gridValue }}</text>
                @endforeach

                <line x1="{{ $openTicketsChartPadding['left'] }}" y1="{{ $openTicketsChartPadding['top'] + $openTicketsPlotHeight }}" x2="{{ $openTicketsChartWidth - $openTicketsChartPadding['right'] }}" y2="{{ $openTicketsChartPadding['top'] + $openTicketsPlotHeight }}" stroke="#d1d5db" stroke-width="1.25" />

                <polyline
                    fill="none"
                    stroke="#E51A2E"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="4"
                    points="{{ $openTicketsLinePoints }}"
                />

                @foreach ($openTicketsChartPoints as $point)
                    <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4" fill="#fff" stroke="#E51A2E" stroke-width="2" />
                    <text
                        x="{{ $point['label_x'] }}"
                        y="{{ $openTicketsChartHeight - 18 }}"
                        fill="#4b5563"
                        font-size="12"
                        font-weight="600"
                        text-anchor="{{ $point['label_anchor'] }}"
                        data-ticket-open-history-day-label="{{ $point['dayInitial'] }}"
                    >
                        {{ $point['dayInitial'] }}
                    </text>
                @endforeach
            </svg>
        </div>
    @else
        <div class="mt-6 rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
            Aún no hay incidencias abiertas creadas en los últimos 30 días.
        </div>
    @endif
</section>
<section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
    <div
        data-dealership-report
        data-dealership-report-url="{{ route('tickets.reports') }}"
        data-dealership-range="{{ $dealershipRange }}"
    >
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-brand-secondary">Tickets por delegaciones</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-secondary/65">
                Este gráfico agrupa todos los tickets por la delegación de la persona que los abrió, para detectar qué ubicaciones generan más carga de trabajo.
            </p>
        </div>
        <div class="rounded-[1.25rem] bg-slate-50 px-4 py-3 text-sm font-semibold text-brand-secondary/70">
            {{ $dealershipReportTotal }} incidencias en total
        </div>
        <div class="inline-flex w-fit max-w-full">
            <select
                id="dealership-range"
                data-dealership-range-select
                class="w-auto min-w-[13rem] max-w-full rounded-2xl border border-brand-secondary/10 bg-white px-4 py-3 text-sm font-semibold text-brand-secondary shadow-sm outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/15"
                style="width: max-content;"
            >
                @foreach ($dealershipRangeOptions as $rangeKey => $rangeOption)
                    <option value="{{ $rangeKey }}" @selected($dealershipRange === $rangeKey)>{{ $rangeOption['label'] ?? $rangeKey }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-6 transition-[opacity,transform] duration-300 ease-out" data-dealership-report-body>
    @if ($dealershipReportRows->isEmpty())
        <div class="mt-6 rounded-[1.5rem] bg-slate-50 px-4 py-4 text-sm text-brand-secondary/65">
            No hay tickets suficientes para mostrar el informe por delegaciones.
        </div>
    @else
        @php
            $dealershipChartRows = $dealershipReportRows->sortByDesc('totalTickets')->values();
            $dealershipMaxTickets = max((int) $dealershipChartRows->max('totalTickets'), 1);
        @endphp

        <div class="mt-6 space-y-3">
            @foreach ($dealershipChartRows as $row)
                @php
                    $barPercentage = ($row['totalTickets'] / $dealershipMaxTickets) * 100;
                @endphp
                <div class="flex items-center gap-3 text-sm">
                    <span class="w-44 shrink-0 truncate font-semibold text-brand-secondary">{{ $row['name'] }}</span>
                    <div class="h-3.5 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-[#E51A2E]" style="width: {{ max(3, $barPercentage) }}%;" aria-hidden="true"></div>
                    </div>
                    <span class="w-10 shrink-0 text-right font-bold text-brand-secondary/70">{{ $row['totalTickets'] }}</span>
                </div>
            @endforeach
        </div>
    @endif
    </div>
</section>
<section class="mt-6 rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-brand-secondary">Personas con más tickets abiertos</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-secondary/65">
                Usuarios que han abierto más tickets, independientemente de su estado.
            </p>
        </div>
    </div>

    @if ($ticketsByRequesterRows->isEmpty())
        <div class="mt-6 rounded-[1.5rem] bg-slate-50 px-4 py-4 text-sm text-brand-secondary/65">
            No hay tickets abiertos para mostrar este ranking.
        </div>
    @else
        @php
            $ticketsByRequesterMax = max((int) $ticketsByRequesterRows->max('totalTickets'), 1);
        @endphp

        <div class="mt-6 space-y-3">
            @foreach ($ticketsByRequesterRows as $row)
                @php
                    $barPercentage = ($row['totalTickets'] / $ticketsByRequesterMax) * 100;
                @endphp
                <div data-open-tickets-requester-bar="{{ $row['id'] }}" class="flex items-center gap-3 text-sm">
                    <span class="w-44 shrink-0 truncate font-semibold text-brand-secondary">{{ $row['name'] }}</span>
                    <div class="h-3.5 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-[#E51A2E]" style="width: {{ max(3, $barPercentage) }}%;" aria-label="{{ $row['totalTickets'] }} tickets"></div>
                    </div>
                    <span class="w-10 shrink-0 text-right font-bold text-brand-secondary/70">{{ $row['totalTickets'] }}</span>
                </div>
            @endforeach
        </div>
    @endif
</section>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const reportOptions = @js($resolutionRangeOptions->map(fn (array $option, string $key): array => [
            'label' => $option['label'] ?? $key,
        ]));

        const buildOptionsMarkup = (options, selectedRange) => {
            return Object.entries(options).map(([value, option]) => {
                const label = option?.label ?? value;
                const selected = value === selectedRange ? ' selected' : '';
                return `<option value="${value}"${selected}>${label}</option>`;
            }).join('');
        };

        const createReportController = ({ root, body, select, endpoint, rangeParam, reportKey, dynamicHeader = null }) => {
            if (!root || !body || !select || !endpoint) {
                return null;
            }

            let controller = null;
            let transitionTimer = null;

            const setLoadingState = (isLoading) => {
                select.disabled = isLoading;
                body.classList.toggle('pointer-events-none', isLoading);
            };

            const setBodyTransitionState = (state) => {
                body.classList.toggle('opacity-0', state === 'hiding');
                body.classList.toggle('translate-y-1', state === 'hiding');
                body.classList.toggle('opacity-100', state === 'showing');
                body.classList.toggle('translate-y-0', state === 'showing');
            };

            body.classList.add('opacity-100', 'translate-y-0');

            const swapReportMarkup = (html) => {
                if (transitionTimer) {
                    window.clearTimeout(transitionTimer);
                    transitionTimer = null;
                }

                setBodyTransitionState('hiding');

                transitionTimer = window.setTimeout(() => {
                    body.innerHTML = html;
                    body.classList.remove('opacity-0', 'translate-y-1');
                    body.classList.add('opacity-100', 'translate-y-0');
                    window.Alpine?.initTree?.(body);
                    transitionTimer = null;
                }, 180);
            };

            const loadReport = async (range) => {
                if (controller) {
                    controller.abort();
                }

                controller = new AbortController();
                setLoadingState(true);

                try {
                    const url = new URL(endpoint, window.location.origin);
                    url.searchParams.set('ajax', '1');
                    url.searchParams.set(rangeParam, range);
                    url.searchParams.set('report', reportKey);

                    const response = await fetch(url.toString(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        signal: controller.signal,
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();

                    if (typeof payload.html === 'string') {
                        swapReportMarkup(payload.html);
                    }
                } catch (error) {
                    if (error?.name !== 'AbortError') {
                        console.error(error);
                    }
                } finally {
                    setLoadingState(false);
                }
            };

            select.addEventListener('change', (event) => {
                void loadReport(event.target.value);
            });

            return {
                loadReport,
                body,
                select,
                root,
                dynamicHeader,
            };
        };

        const closedUsersRoot = document.querySelector('[data-closed-users-report]');

        if (closedUsersRoot) {
            createReportController({
                root: closedUsersRoot,
                endpoint: closedUsersRoot.dataset.closedUsersReportUrl || '',
                select: closedUsersRoot.querySelector('[data-closed-users-range-select]'),
                body: closedUsersRoot.querySelector('[data-closed-users-report-body]'),
                rangeParam: 'closed_users_range',
                reportKey: 'closed_users',
            });
        }

        const resolutionSection = Array.from(document.querySelectorAll('section')).find((section) => {
            const title = section.querySelector('h2');
            return title?.textContent?.includes('Tiempo medio de resolución');
        });

        if (resolutionSection && !resolutionSection.dataset.resolutionReportHydrated) {
            const title = resolutionSection.querySelector('h2');

            if (title) {
                const header = document.createElement('div');
                header.className = 'flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between';

                const titleWrap = document.createElement('div');
                titleWrap.appendChild(title);

                const selectWrap = document.createElement('div');
                selectWrap.className = 'inline-flex w-fit max-w-full';
                selectWrap.innerHTML = `
                    <select id="resolution-range" data-resolution-range-select class="w-auto min-w-[13rem] max-w-full rounded-2xl border border-brand-secondary/10 bg-white px-4 py-3 text-sm font-semibold text-brand-secondary shadow-sm outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/15" style="width: max-content;">
                        ${buildOptionsMarkup(reportOptions, @js($resolutionRange))}
                    </select>
                `;

                header.appendChild(titleWrap);
                header.appendChild(selectWrap);
                resolutionSection.insertBefore(header, resolutionSection.firstChild);

                const body = document.createElement('div');
                body.className = 'mt-6 transition-[opacity,transform] duration-300 ease-out';
                body.dataset.resolutionReportBody = '';

                while (header.nextSibling) {
                    body.appendChild(header.nextSibling);
                }

                resolutionSection.appendChild(body);
                resolutionSection.dataset.resolutionReportHydrated = 'true';

                createReportController({
                    root: resolutionSection,
                    endpoint: @js(route('tickets.reports')),
                    select: resolutionSection.querySelector('[data-resolution-range-select]'),
                    body,
                    rangeParam: 'resolution_range',
                    reportKey: 'resolution',
                });
            }
        }

        const ticketToolRoot = document.querySelector('[data-ticket-tool-report]');

        if (ticketToolRoot) {
            createReportController({
                root: ticketToolRoot,
                endpoint: ticketToolRoot.dataset.ticketToolReportUrl || '',
                select: ticketToolRoot.querySelector('[data-ticket-tool-range-select]'),
                body: ticketToolRoot.querySelector('[data-ticket-tool-report-body]'),
                rangeParam: 'ticket_tool_range',
                reportKey: 'ticket_tool',
            });
        }

        const dealershipRoot = document.querySelector('[data-dealership-report]');

        if (dealershipRoot) {
            createReportController({
                root: dealershipRoot,
                endpoint: dealershipRoot.dataset.dealershipReportUrl || '',
                select: dealershipRoot.querySelector('[data-dealership-range-select]'),
                body: dealershipRoot.querySelector('[data-dealership-report-body]'),
                rangeParam: 'dealership_range',
                reportKey: 'dealership',
            });
        }
    });
</script>
@endsection
