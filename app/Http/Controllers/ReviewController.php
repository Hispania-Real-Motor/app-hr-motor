<?php

namespace App\Http\Controllers;

use App\Models\Dealership;
use App\Jobs\SyncGoogleBusinessProfileReviewsJob;
use App\Models\GoogleBusinessProfileConnection;
use App\Models\GoogleBusinessProfileMonthlySnapshot;
use App\Models\GoogleBusinessProfileReview;
use App\Services\GoogleBusinessProfileReviewService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $dealershipSort = $this->normalizeDealershipSort($request->string('dealership_sort')->toString());

        $payload = Cache::remember($this->reviewsIndexCacheKey(), now()->addMinutes(3), function (): array {
            return [
                'connection' => $this->getConnection(),
                'dealershipSummaries' => $this->buildDealershipSummaries(),
                'locationSummaries' => $this->buildLocationSummaries(),
                'stats' => $this->buildStats(),
            ];
        });

        $dealershipSummaries = $this->sortDealershipSummaries($payload['dealershipSummaries'], $dealershipSort);

        $latestUnanswered = $this->reviewTableExists()
            ? GoogleBusinessProfileReview::query()
                ->withoutSalamanca()
                ->with('dealership')
                ->whereHas('dealership', function ($query): void {
                    $query->withoutSalamanca();
                })
                ->whereNull('reply_comment')
                ->orderByDesc('review_created_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get()
            : collect();

        $latestReviews = $this->reviewTableExists()
            ? GoogleBusinessProfileReview::query()
                ->withoutSalamanca()
                ->with('dealership')
                ->whereHas('dealership', function ($query): void {
                    $query->withoutSalamanca();
                })
                ->orderByDesc('review_created_at')
                ->orderByDesc('id')
                ->limit(18)
                ->get()
            : collect();

        return view('reviews.index', [
            'connection' => $payload['connection'],
            'dealershipSummaries' => $dealershipSummaries,
            'locationSummaries' => $payload['locationSummaries'],
            'latestUnanswered' => $latestUnanswered,
            'latestReviews' => $latestReviews,
            'stats' => $payload['stats'],
            'dealershipSort' => $dealershipSort,
        ]);
    }
    public function all(Request $request): View|JsonResponse
    {
        $reviewsQuery = $this->reviewTableExists()
            ? $this->reviewsQuery($request)->with('dealership')
            : null;

        $reviewsRatingDistribution = $this->buildReviewsRatingDistribution($reviewsQuery);

        $reviewsPaginator = $reviewsQuery
            ? (clone $reviewsQuery)
                ->paginate(10)
                ->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);

        $tableData = [
            'reviews' => $reviewsPaginator,
            'dealerships' => Dealership::query()
                ->withoutSalamanca()
                ->orderBy('name')
                ->get(),
            'filters' => $request->only(['dealership_id', 'status', 'sort', 'search', 'date_from', 'date_to']),
            'reviewsRatingDistribution' => $reviewsRatingDistribution,
        ];

        if ($request->boolean('ajax')) {
            try {
                return response()->json([
                    'html' => view('reviews.partials.activity-results', $tableData)->render(),
                ]);
            } catch (Throwable $exception) {
                report($exception);

                logger()->error('Google Business Profile reviews AJAX render failed.', [
                    'filters' => $tableData['filters'],
                    'message' => $exception->getMessage(),
                ]);

                return response()->json([
                    'message' => 'No se ha podido filtrar la tabla de reseñas.',
                ], 500);
            }
        }

        return view('reviews.all', $tableData);
    }

    public function show(Request $request, Dealership $dealership): View
    {
        abort_unless($this->isVisibleDealership($dealership), 404);

        $reviewsQuery = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->with('dealership')
            ->where('dealership_id', $dealership->id)
            ->when($request->filled('status'), function ($query) use ($request): void {
                if ($request->string('status')->toString() === 'unanswered') {
                    $query->whereNull('reply_comment');
                }
            });

        $reviews = $this->reviewTableExists()
            ? (clone $reviewsQuery)
                ->orderByDesc('review_created_at')
                ->orderByDesc('id')
                ->paginate(10)
                ->withQueryString()
            : collect();

        $stats = $this->reviewTableExists()
            ? $this->buildStatsFromReviewQuery($reviewsQuery)
            : $this->buildStats();

        $historicalSeries = $this->reviewTableExists()
            ? $this->buildHistoricalMonthlyAverageSeries($reviewsQuery)
            : collect();

        return view('reviews.show', [
            'dealership' => $dealership,
            'reviews' => $reviews,
            'historicalSeries' => $historicalSeries,
            'stats' => $stats,
        ]);
    }

    public function location(Request $request, string $locationKey): View
    {
        $locationName = $this->decodeLocationKey($locationKey);

        abort_unless(filled($locationName), 404);
        abort_unless($this->isVisibleLocationName($locationName), 404);

        $reviewsQuery = GoogleBusinessProfileReview::query()
                ->withoutSalamanca()
                ->with('dealership')
                ->where('location_name', $locationName)
                ->when($request->filled('status'), function ($query) use ($request): void {
                    if ($request->string('status')->toString() === 'unanswered') {
                        $query->whereNull('reply_comment');
                    }
                });

        $reviews = $this->reviewTableExists()
            ? (clone $reviewsQuery)
                ->orderByDesc('review_created_at')
                ->orderByDesc('id')
                ->paginate(10)
                ->withQueryString()
            : collect();

        $locationTitle = $reviews->first()?->location_title ?? $locationName;
        $location = (object) [
            'id' => null,
            'name' => $locationTitle,
            'google_business_profile_location_title' => $locationTitle,
            'google_business_profile_location_name' => $locationName,
        ];
        $snapshots = collect();
        $stats = $this->reviewTableExists()
            ? $this->buildStatsFromReviewQuery($reviewsQuery)
            : $this->buildStats();

        $historicalSeries = $this->reviewTableExists()
            ? $this->buildHistoricalMonthlyAverageSeries($reviewsQuery)
            : collect();

        return view('reviews.show', [
            'dealership' => $location,
            'reviews' => $reviews,
            'historicalSeries' => $historicalSeries,
            'stats' => $stats,
        ]);
    }

    public function reports(): View
    {
        return view('reviews.reports', [
            'monthlyReportsUrl' => route('reviews.reports.monthly'),
            'semiannualReportsUrl' => route('reviews.reports.semiannual'),
        ]);
    }

    public function reportsMonthly(): View
    {
        return view('reviews.reports-monthly', [
            'comparisonTableUrl' => route('reviews.reports.monthly.comparison'),
            'comparisonRoscosUrl' => route('reviews.reports.monthly.roscos'),
            'hubUrl' => route('reviews.reports'),
            'semiannualUrl' => route('reviews.reports.semiannual'),
        ]);
    }

    public function reportsMonthlyComparison(): View
    {
        return $this->renderMonthlyComparison(
            'reviews.reports-monthly-comparison',
            'Comparativa delegaciones tabla',
            'reviews.reports.monthly.comparison'
        );
    }

    public function reportsMonthlyComparisonRoscos(): View
    {
        $monthContext = $this->resolveMonthlyComparisonMonthContext();
        $sort = $this->normalizeMonthlyRoscosSort(request()->string('sort')->toString());
        $requestedDirection = request()->string('direction')->toString();
        $direction = $requestedDirection !== ''
            ? $this->normalizeSortDirection($requestedDirection)
            : 'desc';
        $roscos = $this->sortMonthlyRoscosCards(
            $this->buildMonthlyRoscosCards($monthContext['selectedMonth']),
            $sort,
            $direction
        );

        return view('reviews.reports-monthly-roscos', [
            'comparisonTitle' => 'Comparativa delegaciones roscos',
            'hubUrl' => route('reviews.reports.monthly'),
            'availableMonths' => $monthContext['availableMonths'],
            'selectedMonth' => $monthContext['selectedMonth'],
            'roscos' => $roscos,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    private function renderMonthlyComparison(string $view, string $title, string $routeName): View
    {
        $monthContext = $this->resolveMonthlyComparisonMonthContext();
        $snapshots = $monthContext['snapshots'];
        $availableMonths = $monthContext['availableMonths'];
        $selectedMonth = $monthContext['selectedMonth'];

        if ($selectedMonth !== null) {
            $snapshots = $snapshots->filter(function (GoogleBusinessProfileMonthlySnapshot $snapshot) use ($selectedMonth): bool {
                return $snapshot->snapshot_month?->format('Y-m') === $selectedMonth;
            })->values();
        }

        $sort = $this->normalizeMonthlyComparisonSort(request()->string('sort')->toString());
        $direction = $this->normalizeSortDirection(request()->string('direction')->toString());
        $snapshots = $this->sortMonthlyComparisonSnapshots($snapshots, $sort, $direction);

        return view($view, [
            'snapshots' => $snapshots,
            'comparisonTitle' => $title,
            'comparisonRouteName' => $routeName,
            'hubUrl' => route('reviews.reports.monthly'),
            'availableMonths' => $availableMonths,
            'selectedMonth' => $selectedMonth,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * @return array{snapshots:\Illuminate\Support\Collection<int, GoogleBusinessProfileMonthlySnapshot>, availableMonths:\Illuminate\Support\Collection<int, \Carbon\CarbonInterface>, selectedMonth:?string}
     */
    private function resolveMonthlyComparisonMonthContext(): array
    {
        $snapshots = $this->monthlySnapshotsTableExists()
            ? $this->monthlySnapshotsQuery()->get()
            : collect();

        $availableMonths = $snapshots
            ->pluck('snapshot_month')
            ->filter()
            ->unique(fn ($date): string => $date->format('Y-m'))
            ->sortDesc()
            ->values();

        $requestedMonth = request()->string('month')->toString();
        $selectedMonth = $this->normalizeMonthFilter($requestedMonth);

        if ($selectedMonth === null) {
            $selectedMonth = $availableMonths->first()?->format('Y-m');
        }

        return [
            'snapshots' => $snapshots,
            'availableMonths' => $availableMonths,
            'selectedMonth' => $selectedMonth,
        ];
    }

    /**
     * @return Collection<int, array{
     *     key:string,
     *     title:string,
     *     total:int,
     *     red:int,
     *     yellow:int,
     *     green:int,
     *     red_percent:float,
     *     yellow_percent:float,
     *     green_percent:float,
     *     red_angle:float,
     *     yellow_angle:float,
     *     green_angle:float
     * }>
     */
    private function buildMonthlyRoscosCards(?string $selectedMonth): Collection
    {
        if (! $this->reviewTableExists() || $selectedMonth === null) {
            return collect();
        }

        $monthStart = Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $reviews = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->with('dealership')
            ->whereNotNull('dealership_id')
            ->whereHas('dealership', function (EloquentBuilder $query): void {
                $query->withoutSalamanca();
            })
            ->whereBetween('review_created_at', [$monthStart, $monthEnd])
            ->get(['id', 'dealership_id', 'rating', 'review_created_at']);

        return $reviews
            ->groupBy('dealership_id')
            ->map(function (Collection $dealershipReviews): array {
                /** @var GoogleBusinessProfileReview $firstReview */
                $firstReview = $dealershipReviews->first();
                $total = $dealershipReviews->count();
                $red = $dealershipReviews->filter(fn (GoogleBusinessProfileReview $review): bool => in_array((int) $review->rating, [1, 2], true))->count();
                $yellow = $dealershipReviews->filter(fn (GoogleBusinessProfileReview $review): bool => (int) $review->rating === 3)->count();
                $green = $dealershipReviews->filter(fn (GoogleBusinessProfileReview $review): bool => in_array((int) $review->rating, [4, 5], true))->count();
                $redPercent = $total > 0 ? round(($red / $total) * 100, 2) : 0.0;
                $yellowPercent = $total > 0 ? round(($yellow / $total) * 100, 2) : 0.0;
                $greenPercent = $total > 0 ? round(($green / $total) * 100, 2) : 0.0;

                return [
                    'key' => $firstReview->dealership_id ? (string) $firstReview->dealership_id : '',
                    'title' => (string) $firstReview->dealership?->name,
                    'total' => $total,
                    'red' => $red,
                    'yellow' => $yellow,
                    'green' => $green,
                    'red_percent' => $redPercent,
                    'yellow_percent' => $yellowPercent,
                    'green_percent' => $greenPercent,
                    'red_angle' => round(($red / max(1, $total)) * 360, 2),
                    'yellow_angle' => round(($yellow / max(1, $total)) * 360, 2),
                    'green_angle' => round(($green / max(1, $total)) * 360, 2),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    private function normalizeMonthlyRoscosSort(string $sort): string
    {
        return in_array($sort, [
            'total',
            'title',
            'red',
            'yellow',
            'green',
        ], true) ? $sort : 'total';
    }

    /**
     * @param Collection<int, array{
     *     key:string,
     *     title:string,
     *     total:int,
     *     red:int,
     *     yellow:int,
     *     green:int,
     *     red_percent:float,
     *     yellow_percent:float,
     *     green_percent:float,
     *     red_angle:float,
     *     yellow_angle:float,
     *     green_angle:float
     * }> $roscos
     */
    private function sortMonthlyRoscosCards(Collection $roscos, string $sort, string $direction): Collection
    {
        $sorter = function (array $rosco) use ($sort): array|float|int|string {
            return match ($sort) {
                'title' => strtolower((string) ($rosco['title'] ?? '')),
                'red' => (int) ($rosco['red'] ?? 0),
                'yellow' => (int) ($rosco['yellow'] ?? 0),
                'green' => (int) ($rosco['green'] ?? 0),
                default => (int) ($rosco['total'] ?? 0),
            };
        };

        $sorted = $direction === 'desc'
            ? $roscos->sortByDesc($sorter)
            : $roscos->sortBy($sorter);

        return $sorted->values();
    }

    public function reportsSemiannual(): View
    {
        return view('reviews.reports-semiannual', [
            'hubUrl' => route('reviews.reports'),
            'monthlyUrl' => route('reviews.reports.monthly'),
            'chartsUrl' => route('reviews.reports.semiannual.charts'),
        ]);
    }

    public function reportsSemiannualCharts(): View
    {
        return view('reviews.reports-semiannual-charts', [
            'hubUrl' => route('reviews.reports.semiannual'),
            'charts' => $this->buildSemiannualComparativeCharts(),
        ]);
    }
    public function refresh(Request $request, GoogleBusinessProfileReviewService $service, ?Dealership $dealership = null): RedirectResponse
    {
        abort_unless(app_user_has_admin_permission($request->user(), 'reviews.google.manage'), 403);
        abort_if(! $service->hasValidConnection(), 422, 'Conecta Google antes de sincronizar las reseñas.');

        try {
            SyncGoogleBusinessProfileReviewsJob::dispatch($dealership?->id);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        if ($dealership) {
            return back()->with('success', "Sincronizaci\u{F3}n en curso para {$dealership->name}. En breve se actualizar\u{E1}n sus rese\u{F1}as.");
        }

        return back()->with('success', "Sincronizaci\u{F3}n en curso. Se actualizar\u{E1}n las rese\u{F1}as en segundo plano.");
    }

    public function reply(Request $request, GoogleBusinessProfileReviewService $service, GoogleBusinessProfileReview $review): RedirectResponse
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $service->replyToReview($review->review_name, $validated['comment']);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', "No se ha podido responder a la rese\u{F1}a.");
        }

        return back()->with('success', "Respuesta publicada correctamente.");
    }

    private function getConnection(): ?GoogleBusinessProfileConnection
    {
        try {
            return app(GoogleBusinessProfileReviewService::class)->getConnection();
        } catch (Throwable) {
            return null;
        }
    }

    private function reviewTableExists(): bool
    {
        try {
            return Schema::hasTable('google_business_profile_reviews');
        } catch (Throwable) {
            return false;
        }
    }

    private function monthlySnapshotsTableExists(): bool
    {
        try {
            return Schema::hasTable('google_business_profile_monthly_snapshots');
        } catch (Throwable) {
            return false;
        }
    }

    private function reviewsQuery(Request $request)
    {
        $query = GoogleBusinessProfileReview::query()->withoutSalamanca();

        $query->when($request->filled('dealership_id'), function ($builder) use ($request): void {
            $builder->where('dealership_id', $request->integer('dealership_id'));
        });

        $query->when($request->filled('search'), function ($builder) use ($request): void {
            $search = trim((string) $request->string('search'));

            $builder->where(function ($subquery) use ($search): void {
                $subquery->where('reviewer_name', 'like', '%' . $search . '%')
                    ->orWhere('comment', 'like', '%' . $search . '%')
                    ->orWhere('reply_comment', 'like', '%' . $search . '%')
                    ->orWhere('location_title', 'like', '%' . $search . '%')
                    ->orWhere('location_name', 'like', '%' . $search . '%')
                    ->orWhereHas('dealership', function (EloquentBuilder $dealershipQuery) use ($search): void {
                        $dealershipQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        });

        $query->when($request->filled('status'), function ($builder) use ($request): void {
            $status = $request->string('status')->toString();

            if ($status === 'answered') {
                $builder->whereNotNull('reply_comment');
            }

            if ($status === 'unanswered') {
                $builder->whereNull('reply_comment');
            }
        });

        $query->when($request->filled('date_from'), function ($builder) use ($request): void {
            $builder->whereDate('review_created_at', '>=', Carbon::parse($request->string('date_from'))->toDateString());
        });

        $query->when($request->filled('date_to'), function ($builder) use ($request): void {
            $builder->whereDate('review_created_at', '<=', Carbon::parse($request->string('date_to'))->toDateString());
        });

        $sort = $request->string('sort')->toString();
        if ($sort === 'rating_asc') {
            $query->orderBy('rating');
        } elseif ($sort === 'rating_desc') {
            $query->orderByDesc('rating');
        } else {
            $query->orderByDesc('review_created_at')->orderByDesc('id');
        }

        return $query;
    }

    private function monthlySnapshotsQuery()
    {
        return GoogleBusinessProfileMonthlySnapshot::query()
            ->whereHas('dealership', function ($query): void {
                $query->withoutSalamanca();
            })
            ->with('dealership')
            ->orderBy('snapshot_month')
            ->orderBy('dealership_id');
    }

    private function normalizeMonthFilter(?string $month): ?string
    {
        $month = trim((string) $month);

        if ($month === '') {
            return null;
        }

        if (preg_match('/^(\\d{4})-(\\d{2})$/', $month, $matches) !== 1) {
            return null;
        }

        [$all, $year, $monthNumber] = $matches;

        if ((int) $monthNumber < 1 || (int) $monthNumber > 12) {
            return null;
        }

        return sprintf('%04d-%02d', (int) $year, (int) $monthNumber);
    }

    private function normalizeMonthlyComparisonSort(string $sort): string
    {
        return in_array($sort, [
            'month',
            'dealership',
            'total_reviews',
            'average_rating',
            'monthly_reviews',
            'monthly_average_rating',
            'unanswered_reviews',
        ], true) ? $sort : 'dealership';
    }

    private function normalizeSortDirection(string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }

    private function sortMonthlyComparisonSnapshots(Collection $snapshots, string $sort, string $direction): Collection
    {
        $sorter = fn (GoogleBusinessProfileMonthlySnapshot $snapshot) => match ($sort) {
            'month' => $snapshot->snapshot_month?->format('Y-m') ?? '',
            'dealership' => strtolower((string) $snapshot->dealership?->name),
            'total_reviews' => (int) $snapshot->total_reviews,
            'average_rating' => (float) $snapshot->average_rating,
            'monthly_reviews' => (int) $snapshot->monthly_reviews,
            'monthly_average_rating' => (float) $snapshot->monthly_average_rating,
            'unanswered_reviews' => (int) $snapshot->unanswered_reviews,
            default => strtolower((string) $snapshot->dealership?->name),
        };

        $sorted = $direction === 'desc'
            ? $snapshots->sortByDesc($sorter)
            : $snapshots->sortBy($sorter);

        return $sorted->values();
    }

    /**
     * @return array{total:int,red:int,orange:int,green:int,red_percent:float,orange_percent:float,green_percent:float}
     */
    private function buildReviewsRatingDistribution(?EloquentBuilder $query): array
    {
        if (! $query) {
            return [
                'total' => 0,
                'red' => 0,
                'orange' => 0,
                'green' => 0,
                'red_percent' => 0.0,
                'orange_percent' => 0.0,
                'green_percent' => 0.0,
            ];
        }

        $total = (clone $query)->count();
        $red = (clone $query)->whereBetween('rating', [1, 2])->count();
        $orange = (clone $query)->where('rating', 3)->count();
        $green = (clone $query)->whereBetween('rating', [4, 5])->count();

        return [
            'total' => $total,
            'red' => $red,
            'orange' => $orange,
            'green' => $green,
            'red_percent' => $total > 0 ? round(($red / $total) * 100, 1) : 0.0,
            'orange_percent' => $total > 0 ? round(($orange / $total) * 100, 1) : 0.0,
            'green_percent' => $total > 0 ? round(($green / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildDealershipSummaries(): Collection
    {
        $dealerships = Dealership::query()
            ->withoutSalamanca()
            ->whereNotNull('google_business_profile_location_name')
            ->orderBy('name')
            ->get()
            ->groupBy('google_business_profile_location_name')
            ->map(fn (Collection $dealerships): ?Dealership => $dealerships->sortBy('id')->first())
            ->filter()
            ->values();

        return $dealerships->map(function (Dealership $dealership): array {
            $dealershipReviewsQuery = GoogleBusinessProfileReview::query()
                ->withoutSalamanca()
                ->where('dealership_id', $dealership->id);

                $monthStart = now()->startOfMonth();
                $monthEnd = now()->endOfMonth();
                $monthlyReviewsQuery = (clone $dealershipReviewsQuery)
                    ->whereBetween('review_created_at', [$monthStart, $monthEnd]);

                $snapshot = $this->monthlySnapshotsTableExists()
                    ? GoogleBusinessProfileMonthlySnapshot::query()
                        ->where('dealership_id', $dealership->id)
                        ->orderByDesc('snapshot_month')
                        ->first()
                    : null;

                return [
                    'dealership' => $dealership,
                    'total_reviews' => $dealershipReviewsQuery->count(),
                    'average_rating' => round((float) $dealershipReviewsQuery->avg('rating'), 2),
                    'monthly_reviews' => $monthlyReviewsQuery->count(),
                    'monthly_average_rating' => round((float) $monthlyReviewsQuery->avg('rating'), 2),
                    'unanswered_reviews' => (clone $dealershipReviewsQuery)->whereNull('reply_comment')->count(),
                    'snapshot' => $snapshot,
            ];
        });
    }

    private function sortDealershipSummaries(Collection $summaries, string $sort): Collection
    {
        $sorted = match ($sort) {
            'reviews_asc' => $summaries->sortBy(fn (array $summary): int => (int) ($summary['total_reviews'] ?? 0)),
            'reviews_desc' => $summaries->sortByDesc(fn (array $summary): int => (int) ($summary['total_reviews'] ?? 0)),
            'rating_asc' => $summaries->sortBy(fn (array $summary): float => (float) ($summary['average_rating'] ?? 0)),
            'rating_desc' => $summaries->sortByDesc(fn (array $summary): float => (float) ($summary['average_rating'] ?? 0)),
            'monthly_rating_asc' => $summaries->sortBy(fn (array $summary): float => (float) ($summary['monthly_average_rating'] ?? 0)),
            'monthly_rating_desc' => $summaries->sortByDesc(fn (array $summary): float => (float) ($summary['monthly_average_rating'] ?? 0)),
            default => $summaries->sortBy(function (array $summary): string {
                return Str::ascii(Str::lower((string) data_get($summary, 'dealership.name', '')));
            }),
        };

        return $sorted->values();
    }

    private function normalizeDealershipSort(string $sort): string
    {
        return in_array($sort, [
            'alpha',
            'reviews_asc',
            'reviews_desc',
            'rating_asc',
            'rating_desc',
            'monthly_rating_asc',
            'monthly_rating_desc',
        ], true) ? $sort : 'alpha';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLocationSummaries(): Collection
    {
        if (! $this->reviewTableExists()) {
            return collect();
        }

        $linkedLocationNames = Dealership::query()
            ->withoutSalamanca()
            ->whereNotNull('google_business_profile_location_name')
            ->pluck('google_business_profile_location_name')
            ->filter()
            ->map(fn (string $value): string => $this->canonicalGoogleLocationKey($value))
            ->unique()
            ->values()
            ->all();

        $locationNames = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->whereNull('dealership_id')
            ->whereNotNull('location_name')
            ->distinct()
            ->pluck('location_name')
            ->filter()
            ->reject(fn (string $locationName): bool => in_array($this->canonicalGoogleLocationKey($locationName), $linkedLocationNames, true))
            ->values();

        $locationSummaries = $locationNames->map(function (string $locationName): array {
            $locationReviewsQuery = GoogleBusinessProfileReview::query()
                ->withoutSalamanca()
                ->whereNull('dealership_id')
                ->where('location_name', $locationName);

            $locationTitle = (clone $locationReviewsQuery)
                ->orderByDesc('review_created_at')
                ->orderByDesc('id')
                ->value('location_title') ?? $locationName;
            $monthStart = now()->startOfMonth();
            $monthEnd = now()->endOfMonth();
            $monthlyReviewsQuery = (clone $locationReviewsQuery)
                ->whereBetween('review_created_at', [$monthStart, $monthEnd]);

            return [
                'key' => $this->encodeLocationKey($locationName),
                'location_name' => $locationName,
                'location_title' => $locationTitle,
                'total_reviews' => $locationReviewsQuery->count(),
                'average_rating' => round((float) $locationReviewsQuery->avg('rating'), 2),
                'monthly_reviews' => $monthlyReviewsQuery->count(),
                'monthly_average_rating' => round((float) $monthlyReviewsQuery->avg('rating'), 2),
                'unanswered_reviews' => (clone $locationReviewsQuery)->whereNull('reply_comment')->count(),
            ];
            })
            ->values();

        return $locationSummaries->sortByDesc('total_reviews')->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(?Collection $reviews = null): array
    {
        if ($reviews !== null) {
            return [
                'total_reviews' => $reviews->count(),
                'average_rating' => round((float) $reviews->avg('rating'), 2),
                'monthly_reviews' => $reviews->filter(fn (GoogleBusinessProfileReview $review): bool => optional($review->review_created_at)->isCurrentMonth())->count(),
                'monthly_average_rating' => round((float) $reviews->filter(fn (GoogleBusinessProfileReview $review): bool => optional($review->review_created_at)->isCurrentMonth())->avg('rating'), 2),
                'unanswered_reviews' => $reviews->filter(fn (GoogleBusinessProfileReview $review): bool => ! $review->isAnswered())->count(),
            ];
        }

        if (! $this->reviewTableExists()) {
            return [
                'total_reviews' => 0,
                'average_rating' => 0,
                'monthly_reviews' => 0,
                'monthly_average_rating' => 0,
                'unanswered_reviews' => 0,
            ];
        }

        $query = GoogleBusinessProfileReview::query()->withoutSalamanca();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthlyQuery = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->whereBetween('review_created_at', [$monthStart, $monthEnd]);

        return [
            'total_reviews' => $query->count(),
            'average_rating' => round((float) $query->avg('rating'), 2),
            'monthly_reviews' => $monthlyQuery->count(),
            'monthly_average_rating' => round((float) $monthlyQuery->avg('rating'), 2),
            'unanswered_reviews' => (clone $query)->whereNull('reply_comment')->count(),
        ];
    }

    /**
     * @param  EloquentBuilder<GoogleBusinessProfileReview>  $query
     * @return array<string, mixed>
     */
    private function buildStatsFromReviewQuery(EloquentBuilder $query): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthlyQuery = (clone $query)->whereBetween('review_created_at', [$monthStart, $monthEnd]);

        return [
            'total_reviews' => (clone $query)->count(),
            'average_rating' => round((float) (clone $query)->avg('rating'), 2),
            'monthly_reviews' => $monthlyQuery->count(),
            'monthly_average_rating' => round((float) $monthlyQuery->avg('rating'), 2),
            'unanswered_reviews' => (clone $query)->whereNull('reply_comment')->count(),
        ];
    }

    /**
     * @param  EloquentBuilder<GoogleBusinessProfileReview>  $query
     * @return Collection<int, array{key:string,label:string,average:float,has_data:bool}>
     */
    private function buildHistoricalMonthlyAverageSeries(EloquentBuilder $query): Collection
    {
        $monthCount = 6;
        $startMonth = now()->startOfMonth()->subMonths($monthCount - 1);
        $endMonth = now()->endOfMonth();

        $monthlyAverages = (clone $query)
            ->whereBetween('review_created_at', [$startMonth, $endMonth])
            ->get(['review_created_at', 'rating'])
            ->groupBy(function (GoogleBusinessProfileReview $review): string {
                return $review->review_created_at?->format('Y-m') ?? 'sin-fecha';
            })
            ->map(function (Collection $reviews): float {
                return round((float) $reviews->avg('rating'), 2);
            });

        return collect(range(0, $monthCount - 1))->map(function (int $offset) use ($startMonth, $monthlyAverages): array {
            $month = $startMonth->copy()->addMonths($offset);
            $monthKey = $month->format('Y-m');
            $average = (float) ($monthlyAverages[$monthKey] ?? 0);

            return [
                'key' => $monthKey,
                'label' => $month->format('m/Y'),
                'average' => $average,
                'has_data' => array_key_exists($monthKey, $monthlyAverages->all()),
            ];
        });
    }

    /**
     * @return Collection<int, array{
     *     key:string,
     *     title:string,
     *     total_reviews:int,
     *     series:Collection<int, array{key:string,label:string,average:float,has_data:bool}>
     * }>
     */
    private function buildSemiannualComparativeCharts(): Collection
    {
        if (! $this->reviewTableExists()) {
            return collect();
        }

        $monthCount = 6;
        $startMonth = now()->startOfMonth()->subMonths($monthCount - 1);
        $endMonth = now()->endOfMonth();

        $reviews = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->with('dealership')
            ->whereNotNull('dealership_id')
            ->whereHas('dealership', function (EloquentBuilder $query): void {
                $query->withoutSalamanca();
            })
            ->whereBetween('review_created_at', [$startMonth, $endMonth])
            ->get(['dealership_id', 'review_created_at', 'rating']);

        return $reviews
            ->groupBy('dealership_id')
            ->map(function (Collection $dealershipReviews) use ($startMonth, $monthCount): array {
                /** @var GoogleBusinessProfileReview $firstReview */
                $firstReview = $dealershipReviews->first();
                $series = collect(range(0, $monthCount - 1))->map(function (int $offset) use ($startMonth, $dealershipReviews): array {
                    $month = $startMonth->copy()->addMonths($offset);
                    $monthKey = $month->format('Y-m');
                    $monthReviews = $dealershipReviews->filter(function (GoogleBusinessProfileReview $review) use ($monthKey): bool {
                        return $review->review_created_at?->format('Y-m') === $monthKey;
                    });
                    $average = round((float) $monthReviews->avg('rating'), 2);

                    return [
                        'key' => $monthKey,
                        'label' => $month->format('m/Y'),
                        'average' => $average,
                        'has_data' => $monthReviews->isNotEmpty(),
                    ];
                });

                return [
                    'key' => (string) $firstReview->dealership_id,
                    'title' => (string) $firstReview->dealership?->name,
                    'total_reviews' => $dealershipReviews->count(),
                    'series' => $series,
                ];
            })
            ->sortByDesc('total_reviews')
            ->values();
    }

    private function encodeLocationKey(string $locationName): string
    {
        return rtrim(strtr(base64_encode($locationName), '+/', '-_'), '=');
    }

    private function decodeLocationKey(string $locationKey): ?string
    {
        $decoded = base64_decode(strtr($locationKey, '-_', '+/'), true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function isVisibleDealership(Dealership $dealership): bool
    {
        $normalizedValues = [
            $this->normalizeTextForFilter($dealership->name),
            $this->normalizeTextForFilter($dealership->google_business_profile_location_name),
            $this->normalizeTextForFilter($dealership->google_business_profile_location_title),
        ];

        foreach ($normalizedValues as $normalizedValue) {
            if ($normalizedValue !== '' && str_contains($normalizedValue, 'salamanca')) {
                return false;
            }
        }

        return true;
    }

    private function isVisibleLocationName(string $locationName): bool
    {
        return ! str_contains($this->normalizeTextForFilter($locationName), 'salamanca');
    }

    private function normalizeTextForFilter(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function canonicalGoogleLocationKey(?string $locationName): string
    {
        $locationName = trim((string) $locationName);

        if ($locationName === '') {
            return '';
        }

        if (preg_match('#(?:accounts/[^/]+/)?locations/([^/]+)$#i', $locationName, $matches) === 1) {
            return 'locations/' . strtolower($matches[1]);
        }

        return strtolower($locationName);
    }

    private function reviewsIndexCacheKey(): string
    {
        return 'reviews.index.dashboard.v1';
    }

}


