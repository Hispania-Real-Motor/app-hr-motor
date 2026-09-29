<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\GoogleBusinessProfileConnection;
use App\Models\GoogleBusinessProfileMonthlySnapshot;
use App\Models\GoogleBusinessProfileReview;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleBusinessProfileReviewService
{
    private const PROVIDER = 'google_business_profile';

    public function getConnection(): ?GoogleBusinessProfileConnection
    {
        if (! Schema::hasTable('google_business_profile_connections')) {
            return null;
        }

        return GoogleBusinessProfileConnection::query()
            ->where('provider', self::PROVIDER)
            ->first();
    }

    public function hasValidConnection(): bool
    {
        $connection = $this->getConnection();

        return $connection !== null
            && filled($connection->access_token)
            && filled($connection->refresh_token)
            && filled(config('services.google_business_profile.client_id'))
            && filled(config('services.google_business_profile.client_secret'))
            && filled(config('services.google_business_profile.token_url'));
    }

    public function saveAuthorizationCodeTokens(string $code): GoogleBusinessProfileConnection
    {
        $this->ensureRequiredTablesExist();

        $redirectUri = route('google-business-profile.callback', [], true);

        $response = Http::asForm()
            ->post(config('services.google_business_profile.token_url'), [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.google_business_profile.client_id'),
                'client_secret' => config('services.google_business_profile.client_secret'),
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

        if ($response->failed()) {
            Log::error('Google Business Profile token exchange failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'redirect_uri' => $redirectUri,
            ]);
        }

        $response->throw();

        return $this->persistTokens($response->json());
    }

    public function sync(?Dealership $targetDealership = null): int
    {
        $this->ensureRequiredTablesExist();

        $connection = $this->getConnection();

        if (! $connection) {
            throw new RuntimeException('No hay ninguna conexion de Google Business Profile configurada.');
        }

        $account = $this->resolveAccount($connection);
        $syncedAt = now();
        $mappedDealerships = collect();
        $syncedReviewCount = 0;

        Log::info('Google Business Profile sync started.', [
            'connection_id' => $connection->id,
            'account_name' => data_get($account, 'accountName'),
            'account_resource_name' => data_get($account, 'name'),
            'target_dealership_id' => $targetDealership?->id,
            'target_dealership_name' => $targetDealership?->name,
        ]);

        if ($targetDealership) {
            $syncedReviewCount = $this->syncSingleDealership(
                connection: $connection,
                account: $account,
                dealership: $targetDealership,
                syncedAt: $syncedAt,
                mappedDealerships: $mappedDealerships
            );
        } else {
            $locations = $this->fetchLocations($connection, $account['name']);
            $currentLocationNames = collect($locations)
                ->pluck('name')
                ->filter()
                ->map(fn (string $value): string => $this->canonicalGoogleLocationKey($value))
                ->unique()
                ->values()
                ->all();
            $dealershipCount = Dealership::query()
                ->withoutSalamanca()
                ->count();

            Log::info('Google Business Profile sync location scan started.', [
                'locations_found' => count($locations),
                'dealership_count' => $dealershipCount,
            ]);

            if ($dealershipCount === 0) {
                Log::warning('Google Business Profile sync found no dealership records in the database.', [
                    'account_resource_name' => data_get($account, 'name'),
                ]);
            }

            foreach ($locations as $location) {
                $syncedReviewCount += $this->syncLocation(
                    connection: $connection,
                    account: $account,
                    location: $location,
                    syncedAt: $syncedAt,
                    mappedDealerships: $mappedDealerships
                );
            }

            $this->pruneReviewsForMissingGoogleLocations($currentLocationNames);
        }

        $this->dedupeDuplicateReviewRows();
        $this->logUnmappedDealerships($mappedDealerships);

        DB::transaction(function () use ($connection, $syncedAt, $account, $mappedDealerships, $syncedReviewCount, $targetDealership): void {
            $this->refreshMonthlySnapshots($syncedAt, $targetDealership ? [$targetDealership->id] : null);

            $connection->forceFill([
                'account_name' => data_get($account, 'accountName'),
                'account_resource_name' => data_get($account, 'name'),
                'last_synced_at' => $syncedAt,
                'metadata' => array_merge($connection->metadata ?? [], [
                    'location_count' => $syncedReviewCount,
                    'mapped_dealership_ids' => $mappedDealerships->unique()->values()->all(),
                ]),
            ])->save();

            Log::info('Google Business Profile sync finished.', [
                'connection_id' => $connection->id,
                'account_resource_name' => data_get($account, 'name'),
                'review_rows' => $syncedReviewCount,
                'mapped_dealerships' => $mappedDealerships->unique()->values()->all(),
            ]);
        });

        Cache::forget('reviews.index.dashboard.v1');

        return $syncedReviewCount;
    }

    public function dedupeDuplicateReviewRows(): int
    {
        $this->ensureRequiredTablesExist();

        if (! $this->reviewTableExists()) {
            return 0;
        }

        $deletedCount = 0;

        $deletedCount += $this->dedupeDuplicateReviewRowsByReviewName();
        $deletedCount += $this->dedupeDuplicateReviewRowsByFingerprint();

        return $deletedCount;
    }

    private function reviewTableExists(): bool
    {
        return Schema::hasTable('google_business_profile_reviews');
    }

    private function monthlySnapshotsTableExists(): bool
    {
        return Schema::hasTable('google_business_profile_monthly_snapshots');
    }

    public function buildDuplicateReviewKey(GoogleBusinessProfileReview $review): string
    {
        $parts = [
            $this->normalizeText((string) ($this->canonicalGoogleLocationKey($review->location_name) ?: $review->location_title)),
            $this->normalizeText((string) $review->reviewer_name),
            $this->normalizeFingerprintValue($review->rating),
            $this->normalizeFingerprintValue($review->review_created_at?->format('Y-m-d H:i:s')),
            $this->normalizeText((string) $review->comment),
            $this->normalizeText((string) $review->reply_comment),
        ];

        return implode('|', $parts);
    }

    private function dedupeDuplicateReviewRowsByReviewName(): int
    {
        $duplicateReviewNames = GoogleBusinessProfileReview::query()
            ->select('review_name')
            ->whereNotNull('review_name')
            ->groupBy('review_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('review_name');

        if ($duplicateReviewNames->isEmpty()) {
            return 0;
        }

        $deletedCount = 0;

        foreach ($duplicateReviewNames as $reviewName) {
            $rows = GoogleBusinessProfileReview::query()
                ->where('review_name', $reviewName)
                ->orderByRaw('CASE WHEN dealership_id IS NULL THEN 0 ELSE 1 END DESC')
                ->orderByDesc('synced_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            if ($rows->count() <= 1) {
                continue;
            }

            $idsToDelete = $rows->slice(1)->pluck('id')->all();

            if ($idsToDelete === []) {
                continue;
            }

            GoogleBusinessProfileReview::query()
                ->whereIn('id', $idsToDelete)
                ->delete();

            $deletedCount += count($idsToDelete);
        }

        if ($deletedCount > 0) {
            Cache::forget('reviews.index.dashboard.v1');

            Log::info('Google Business Profile duplicate review rows pruned.', [
                'deleted_count' => $deletedCount,
                'duplicate_review_names' => $duplicateReviewNames->count(),
            ]);
        }

        return $deletedCount;
    }

    private function dedupeDuplicateReviewRowsByFingerprint(): int
    {
        $query = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->select([
                'id',
                'dealership_id',
                'location_name',
                'location_title',
                'review_name',
                'reviewer_name',
                'rating',
                'comment',
                'reply_comment',
                'reply_updated_at',
                'review_created_at',
                'review_updated_at',
                'synced_at',
                'updated_at',
            ])
            ->orderByRaw('CASE WHEN dealership_id IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('location_name')
            ->orderBy('location_title')
            ->orderBy('reviewer_name')
            ->orderBy('rating')
            ->orderBy('review_created_at')
            ->orderBy('comment')
            ->orderBy('reply_comment')
            ->orderBy('id');

        $previousFingerprint = null;
        $idsToDelete = [];
        $rowsProcessed = 0;

        foreach ($query->cursor() as $row) {
            $rowsProcessed++;
            $fingerprint = $this->buildDuplicateReviewKey($row);

            if ($fingerprint === '') {
                continue;
            }

            if ($previousFingerprint === $fingerprint) {
                $idsToDelete[] = $row->id;
                continue;
            }

            $previousFingerprint = $fingerprint;
            
        }

        if ($idsToDelete === []) {
            return 0;
        }

        GoogleBusinessProfileReview::query()
            ->whereIn('id', $idsToDelete)
            ->delete();

        Cache::forget('reviews.index.dashboard.v1');

        Log::info('Google Business Profile review rows pruned by visible fingerprint.', [
            'rows_processed' => $rowsProcessed,
            'deleted_count' => count($idsToDelete),
        ]);

        return count($idsToDelete);
    }

    private function normalizeFingerprintValue(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = Str::lower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
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

    public function canonicalGoogleReviewKey(?string $reviewName): string
    {
        $reviewName = trim((string) $reviewName);

        if ($reviewName === '') {
            return '';
        }

        if (preg_match('#(?:accounts/[^/]+/)?locations/([^/]+)/reviews/([^/]+)$#i', $reviewName, $matches) === 1) {
            return Str::lower('locations/' . $matches[1] . '/reviews/' . $matches[2]);
        }

        return Str::lower($reviewName);
    }

    private function logUnmappedDealerships(Collection $mappedDealerships): void
    {
        $unmappedDealerships = Dealership::query()
            ->withoutSalamanca()
            ->select(['id', 'name', 'google_business_profile_location_title', 'google_business_profile_location_name'])
            ->whereNotIn('id', $mappedDealerships->unique()->values()->all())
            ->orderBy('name')
            ->get();

        if ($unmappedDealerships->isEmpty()) {
            Log::info('Google Business Profile sync mapped all eligible dealerships.', [
                'mapped_dealership_ids' => $mappedDealerships->unique()->values()->all(),
            ]);

            return;
        }

        Log::warning('Google Business Profile sync left some dealerships unmapped.', [
            'unmapped_count' => $unmappedDealerships->count(),
            'unmapped_dealerships' => $unmappedDealerships->map(function (Dealership $dealership): array {
                return [
                    'id' => $dealership->id,
                    'name' => $dealership->name,
                    'google_business_profile_location_name' => $dealership->google_business_profile_location_name,
                    'google_business_profile_location_title' => $dealership->google_business_profile_location_title,
                ];
            })->values()->all(),
        ]);
    }

    public function replyToReview(string $reviewName, string $comment): GoogleBusinessProfileReview
    {
        $this->ensureRequiredTablesExist();

        $review = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->where('review_name', $reviewName)
            ->firstOrFail();

        $connection = $this->getConnection();

        if (! $connection) {
            throw new RuntimeException('No hay ninguna conexion de Google Business Profile configurada.');
        }

        $response = $this->requestWithAutoRefresh($connection, function (string $accessToken) use ($reviewName, $comment) {
            return Http::withToken($accessToken)
                ->put(sprintf('https://mybusiness.googleapis.com/v4/%s/reply', $reviewName), [
                    'comment' => $comment,
                ]);
        });

        $payload = $response->throw()->json();
        $replyComment = data_get($payload, 'comment', $comment);
        $replyUpdatedAt = $this->parseTimestamp(data_get($payload, 'updateTime')) ?? now();

        $review->forceFill([
            'reply_comment' => $replyComment,
            'reply_updated_at' => $replyUpdatedAt,
            'synced_at' => now(),
        ])->save();

        Cache::forget('reviews.index.dashboard.v1');

        return $review->fresh(['dealership']);
    }

    /**
     * @return array{
     *     dealership_id:int,
     *     dealership_name:string,
     *     location_name:string,
     *     location_title:?string,
     *     location_resource_name:string,
     *     reviews:array<int, array<string, mixed>>
     * }
     */
    public function fetchLatestReviewsForDealership(Dealership $dealership, int $limit = 10): array
    {
        $this->ensureRequiredTablesExist();

        $limit = max(1, $limit);
        $connection = $this->getConnection();

        if (! $connection) {
            throw new RuntimeException('No hay ninguna conexion de Google Business Profile configurada.');
        }

        $account = $this->resolveAccount($connection);
        [$locationName, $locationTitle] = $this->resolveLocationForDealership($connection, $account, $dealership);
        $locationResourceName = $this->buildLocationResourceName($account['name'], $locationName);
        $reviews = $this->fetchReviews($connection, $locationResourceName, $limit);

        return [
            'dealership_id' => (int) $dealership->id,
            'dealership_name' => (string) $dealership->name,
            'location_name' => (string) $locationName,
            'location_title' => $locationTitle,
            'location_resource_name' => $locationResourceName,
            'reviews' => array_map(function (array $review): array {
                return [
                    'review_name' => $review['name'] ?? null,
                    'reviewer_name' => data_get($review, 'reviewer.displayName') ?? data_get($review, 'reviewer.name'),
                    'rating' => $this->ratingToInteger(data_get($review, 'starRating') ?? data_get($review, 'rating')),
                    'comment' => $this->sanitizeReviewComment(data_get($review, 'comment')),
                    'reply_comment' => data_get($review, 'reviewReply.comment'),
                    'review_created_at' => $this->parseTimestamp(data_get($review, 'createTime'))?->toDateTimeString(),
                    'review_updated_at' => $this->parseTimestamp(data_get($review, 'updateTime'))?->toDateTimeString(),
                ];
            }, $reviews),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAccount(GoogleBusinessProfileConnection $connection): array
    {
        $accounts = $this->listAccounts($connection);
        $targetName = (string) config('services.google_business_profile.account_group_name');
        $normalizedTargetName = $this->normalizeText($targetName);

        $matchedAccounts = collect($accounts)->filter(function (array $account) use ($targetName): bool {
            $accountName = (string) data_get($account, 'accountName');

            return $this->normalizeText($accountName) === $this->normalizeText($targetName);
        });

        $matchedAccount = $matchedAccounts->first(fn (array $account): bool => Str::upper(trim((string) data_get($account, 'type'))) === 'LOCATION_GROUP')
            ?? $matchedAccounts->first();

        if ($matchedAccount) {
            Log::info('Google Business Profile account resolved from exact account name.', [
                'target_name' => $targetName,
                'normalized_target_name' => $normalizedTargetName,
                'account_name' => data_get($matchedAccount, 'accountName'),
                'account_resource_name' => data_get($matchedAccount, 'name'),
                'account_type' => data_get($matchedAccount, 'type'),
            ]);

            return $matchedAccount;
        }

        $availableAccounts = collect($accounts)->map(function (array $account): array {
            return [
                'name' => data_get($account, 'name'),
                'accountName' => data_get($account, 'accountName'),
                'type' => data_get($account, 'type'),
            ];
        })->values()->all();

        throw new RuntimeException('No se ha encontrado la cuenta de grupo exacta "' . $targetName . '" en Google Business Profile. Cuentas disponibles: ' . json_encode($availableAccounts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listAccounts(GoogleBusinessProfileConnection $connection): array
    {
        $endpoint = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

        $response = $this->requestWithAutoRefresh($connection, function (string $accessToken) use ($endpoint) {
            return Http::withToken($accessToken)->get($endpoint, [
                'pageSize' => 50,
            ]);
        });

        try {
            return $response->throw()->json('accounts', []);
        } catch (\Throwable $exception) {
            Log::error('Google Business Profile listAccounts failed.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLocations(GoogleBusinessProfileConnection $connection, string $accountResourceName): array
    {
        $locations = [];
        $nextPageToken = null;
        $endpoint = sprintf('https://mybusinessbusinessinformation.googleapis.com/v1/%s/locations', $accountResourceName);

        do {
            $response = $this->requestWithAutoRefresh($connection, function (string $accessToken) use ($accountResourceName, $nextPageToken) {
                $query = [
                    'pageSize' => 100,
                    'readMask' => 'name,title,storeCode,storefrontAddress.locality,storefrontAddress.administrativeArea,storefrontAddress.postalCode',
                ];

                if ($nextPageToken) {
                    $query['pageToken'] = $nextPageToken;
                }

                return Http::withToken($accessToken)->get(
                    sprintf('https://mybusinessbusinessinformation.googleapis.com/v1/%s/locations', $accountResourceName),
                    $query
                );
            });

            try {
                $payload = $response->throw()->json();
            } catch (\Throwable $exception) {
                Log::error('Google Business Profile fetchLocations failed.', [
                    'endpoint' => $endpoint,
                    'account_resource_name' => $accountResourceName,
                    'page_token' => $nextPageToken,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'message' => $exception->getMessage(),
                ]);

                throw $exception;
            }
            $locations = [...$locations, ...($payload['locations'] ?? [])];
            $nextPageToken = $payload['nextPageToken'] ?? null;
        } while ($nextPageToken);

        return $locations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchReviews(GoogleBusinessProfileConnection $connection, string $locationResourceName, ?int $limit = null): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $reviews = [];
        $nextPageToken = null;
        $endpoint = sprintf('https://mybusiness.googleapis.com/v4/%s/reviews', $locationResourceName);
        $remaining = $limit;

        do {
            $response = $this->requestWithAutoRefresh($connection, function (string $accessToken) use ($locationResourceName, $nextPageToken, $remaining) {
                $query = [
                    'pageSize' => $remaining !== null ? min(50, max(1, $remaining)) : 50,
                    'orderBy' => 'updateTime desc',
                ];

                if ($nextPageToken) {
                    $query['pageToken'] = $nextPageToken;
                }

                return Http::withToken($accessToken)->get(
                    sprintf('https://mybusiness.googleapis.com/v4/%s/reviews', $locationResourceName),
                    $query
                );
            });

            try {
                $payload = $response->throw()->json();
            } catch (\Throwable $exception) {
                Log::error('Google Business Profile fetchReviews failed.', [
                    'endpoint' => $endpoint,
                    'location_name' => $locationResourceName,
                    'page_token' => $nextPageToken,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'message' => $exception->getMessage(),
                ]);

                throw $exception;
            }
            $pageReviews = $payload['reviews'] ?? [];

            if ($remaining !== null) {
                $pageReviews = array_slice($pageReviews, 0, $remaining);
            }

            $reviews = [...$reviews, ...$pageReviews];

            if ($remaining !== null) {
                $remaining -= count($pageReviews);
            }

            $nextPageToken = $payload['nextPageToken'] ?? null;
        } while ($nextPageToken && ($remaining === null || $remaining > 0));

        return $reviews;
    }

    private function persistTokens(array $payload, ?GoogleBusinessProfileConnection $connection = null): GoogleBusinessProfileConnection
    {
        $connection ??= GoogleBusinessProfileConnection::query()
            ->firstOrNew([
                'provider' => self::PROVIDER,
            ]);

        $metadata = $connection->metadata ?? [];
        $metadata['issued_at'] = $payload['issued_at'] ?? ($metadata['issued_at'] ?? null);

        $connection->forceFill([
            'provider' => self::PROVIDER,
            'access_token' => $payload['access_token'] ?? $connection->access_token,
            'refresh_token' => $payload['refresh_token'] ?? $connection->refresh_token,
            'token_type' => $payload['token_type'] ?? $connection->token_type,
            'scope' => $payload['scope'] ?? $connection->scope,
            'expires_at' => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'])
                : $connection->expires_at,
            'metadata' => $metadata,
        ])->save();

        return $connection->refresh();
    }

    private function refreshAccessToken(GoogleBusinessProfileConnection $connection): GoogleBusinessProfileConnection
    {
        $response = Http::asForm()
            ->post(config('services.google_business_profile.token_url'), [
                'grant_type' => 'refresh_token',
                'client_id' => config('services.google_business_profile.client_id'),
                'client_secret' => config('services.google_business_profile.client_secret'),
                'refresh_token' => $connection->refresh_token,
            ])
            ->throw()
            ->json();

        return $this->persistTokens($response, $connection);
    }

    private function requestWithAutoRefresh(GoogleBusinessProfileConnection $connection, callable $request): Response
    {
        if (blank($connection->access_token) && filled($connection->refresh_token)) {
            $connection = $this->refreshAccessToken($connection);
        }

        if (blank($connection->access_token)) {
            throw new RuntimeException('La conexion no tiene un access token valido.');
        }

        $response = $request($connection->access_token);

        if ($response->status() !== 401 || blank($connection->refresh_token)) {
            return $response;
        }

        $connection = $this->refreshAccessToken($connection);

        return $request($connection->access_token);
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function buildReviewRow(array $review, string $locationName, ?string $locationTitle, ?int $dealershipId, Carbon $syncedAt): array
    {
        $replyComment = data_get($review, 'reviewReply.comment');
        $replyUpdatedAt = $this->parseTimestamp(data_get($review, 'reviewReply.updateTime'));
        $cleanComment = $this->sanitizeReviewComment(data_get($review, 'comment'));

        return [
            'dealership_id' => $dealershipId,
            'location_name' => $this->sanitizeUtf8String($locationName),
            'location_title' => $this->sanitizeUtf8String($locationTitle),
            'review_name' => $this->sanitizeUtf8String((string) data_get($review, 'name')),
            'reviewer_name' => $this->sanitizeUtf8String($this->stringOrNull(data_get($review, 'reviewer.displayName') ?? data_get($review, 'reviewer.name'))),
            'reviewer_photo_url' => $this->sanitizeUtf8String($this->stringOrNull(data_get($review, 'reviewer.profilePhotoUrl'))),
            'rating' => $this->ratingToInteger(data_get($review, 'starRating') ?? data_get($review, 'rating')),
            'comment' => $this->sanitizeUtf8String($cleanComment),
            'reply_name' => $this->sanitizeUtf8String($this->stringOrNull(data_get($review, 'reviewReply.name'))),
            'reply_comment' => $this->sanitizeUtf8String($this->stringOrNull($replyComment)),
            'reply_updated_at' => $replyUpdatedAt?->toDateTimeString(),
            'review_created_at' => $this->parseTimestamp(data_get($review, 'createTime'))?->toDateTimeString(),
            'review_updated_at' => $this->parseTimestamp(data_get($review, 'updateTime'))?->toDateTimeString(),
            'synced_at' => $syncedAt->toDateTimeString(),
            'raw_payload' => $this->sanitizeReviewPayload($review),
            'created_at' => $syncedAt->toDateTimeString(),
            'updated_at' => $syncedAt->toDateTimeString(),
        ];
    }

    private function buildLocationResourceName(string $accountResourceName, string $locationName): string
    {
        $locationName = ltrim($locationName, '/');

        if (str_starts_with($locationName, 'accounts/')) {
            return $locationName;
        }

        return rtrim($accountResourceName, '/') . '/' . $locationName;
    }

    private function refreshMonthlySnapshots(Carbon $capturedAt, ?array $dealershipIds = null): void
    {
        $snapshotMonth = $capturedAt->copy()->startOfMonth();
        $dealerships = Dealership::query()
            ->withoutSalamanca()
            ->when($dealershipIds !== null, fn ($query) => $query->whereIn('id', $dealershipIds))
            ->orderBy('name')
            ->get();

        foreach ($dealerships as $dealership) {
            $reviewsQuery = GoogleBusinessProfileReview::query()
                ->withoutSalamanca()
                ->where('dealership_id', $dealership->id);

            $allReviews = (clone $reviewsQuery)->get();
            $monthReviews = (clone $reviewsQuery)
                ->whereBetween('review_created_at', [$snapshotMonth->copy()->startOfMonth(), $snapshotMonth->copy()->endOfMonth()])
                ->get();

                $snapshot = GoogleBusinessProfileMonthlySnapshot::query()
                    ->where('dealership_id', $dealership->id)
                    ->whereDate('snapshot_month', $snapshotMonth->toDateString())
                    ->first();

                $snapshotData = [
                    'dealership_id' => $dealership->id,
                    'snapshot_month' => $snapshotMonth->toDateString(),
                    'total_reviews' => $allReviews->count(),
                    'average_rating' => $allReviews->avg('rating'),
                    'monthly_reviews' => $monthReviews->count(),
                    'monthly_average_rating' => $monthReviews->avg('rating'),
                    'unanswered_reviews' => $allReviews->filter(fn (GoogleBusinessProfileReview $review): bool => ! $review->isAnswered())->count(),
                    'captured_at' => $capturedAt,
                ];

                if ($snapshot) {
                    $snapshot->forceFill($snapshotData)->save();
                } else {
                    GoogleBusinessProfileMonthlySnapshot::query()->create($snapshotData);
                }
            }
        }

    /**
     * Rebuilds monthly snapshots for a specific month or for every available review month.
     *
     * @return int Number of snapshots upserted
     */
    public function rebuildMonthlySnapshots(?string $month = null, ?array $dealershipIds = null): int
    {
        $this->ensureRequiredTablesExist();

        if (! $this->monthlySnapshotsTableExists()) {
            return 0;
        }

        $dealerships = Dealership::query()
            ->withoutSalamanca()
            ->when($dealershipIds !== null, fn ($query) => $query->whereIn('id', $dealershipIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($dealerships->isEmpty()) {
            return 0;
        }

        $reviews = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->whereIn('dealership_id', $dealerships->pluck('id')->all())
            ->whereNotNull('review_created_at')
            ->orderBy('review_created_at')
            ->orderBy('id')
            ->get(['dealership_id', 'rating', 'reply_comment', 'review_created_at']);

        if ($reviews->isEmpty()) {
            return 0;
        }

        $selectedMonths = $month !== null
            ? collect([$this->normalizeMonthFilter($month)])
            : $reviews
                ->groupBy(fn (GoogleBusinessProfileReview $review): string => $review->review_created_at?->format('Y-m') ?? '')
                ->keys();

        $selectedMonths = $selectedMonths
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($selectedMonths->isEmpty()) {
            return 0;
        }

        $reviewsByDealership = $reviews->groupBy('dealership_id');
        $upsertedCount = 0;

        foreach ($selectedMonths as $monthKey) {
            $snapshotMonth = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();
            $monthStart = $snapshotMonth->copy()->startOfMonth();
            $monthEnd = $snapshotMonth->copy()->endOfMonth();
            $capturedAt = $monthEnd->copy()->endOfDay();

            foreach ($dealerships as $dealership) {
                /** @var Collection<int, GoogleBusinessProfileReview> $dealershipReviews */
                $dealershipReviews = $reviewsByDealership->get($dealership->id, collect());
                $reviewsUpToMonth = $dealershipReviews->filter(function (GoogleBusinessProfileReview $review) use ($monthEnd): bool {
                    return $review->review_created_at !== null && $review->review_created_at->lte($monthEnd);
                });

                if ($reviewsUpToMonth->isEmpty()) {
                    continue;
                }

                $monthReviews = $reviewsUpToMonth->filter(function (GoogleBusinessProfileReview $review) use ($monthStart, $monthEnd): bool {
                    return $review->review_created_at !== null
                        && $review->review_created_at->betweenIncluded($monthStart, $monthEnd);
                });

                $snapshot = GoogleBusinessProfileMonthlySnapshot::query()
                    ->where('dealership_id', $dealership->id)
                    ->whereDate('snapshot_month', $snapshotMonth->toDateString())
                    ->first();

                $snapshotData = [
                    'dealership_id' => $dealership->id,
                    'snapshot_month' => $snapshotMonth->toDateString(),
                    'total_reviews' => $reviewsUpToMonth->count(),
                    'average_rating' => $reviewsUpToMonth->avg('rating'),
                    'monthly_reviews' => $monthReviews->count(),
                    'monthly_average_rating' => $monthReviews->avg('rating'),
                    'unanswered_reviews' => $reviewsUpToMonth->filter(fn (GoogleBusinessProfileReview $review): bool => ! $review->isAnswered())->count(),
                    'captured_at' => $capturedAt,
                ];

                if ($snapshot) {
                    $snapshot->forceFill($snapshotData)->save();
                } else {
                    GoogleBusinessProfileMonthlySnapshot::query()->create($snapshotData);
                }

                $upsertedCount++;
            }
        }

        Cache::forget('reviews.index.dashboard.v1');

        return $upsertedCount;
    }

    private function syncLocation(
        GoogleBusinessProfileConnection $connection,
        array $account,
        array $location,
        Carbon $syncedAt,
        Collection $mappedDealerships
    ): int {
        $locationName = $this->stringOrNull(data_get($location, 'name'));
        $canonicalLocationName = $this->canonicalGoogleLocationKey($locationName);

        if (! $locationName) {
            Log::warning('Google Business Profile location skipped because it has no resource name.', [
                'account_resource_name' => data_get($account, 'name'),
                'location_payload' => $location,
            ]);

            return 0;
        }

        $locationTitle = $this->extractLocationTitle($location);
        $locationTerms = $this->extractLocationMatchTerms($location);

        if ($this->shouldSkipSalamancaLocation($locationName, $locationTitle, $locationTerms)) {
            Log::info('Google Business Profile location skipped because it refers to Salamanca.', [
                'account_resource_name' => data_get($account, 'name'),
                'location_name' => $locationName,
                'location_title' => $locationTitle,
                'location_terms' => $locationTerms,
            ]);

            return 0;
        }

        $dealership = $this->resolveDealershipForLocation($locationName, $locationTitle, $locationTerms);
        $locationResourceName = $this->buildLocationResourceName($account['name'], $locationName);

        Log::info('Google Business Profile location processing started.', [
            'location_name' => $locationName,
            'location_title' => $locationTitle,
            'dealership_id' => $dealership?->id,
            'dealership_name' => $dealership?->name,
            'location_resource_name' => $locationResourceName,
        ]);

        if ($dealership) {
            $mappedDealerships->push($dealership->id);
            $dealership->forceFill([
                'google_business_profile_location_name' => $canonicalLocationName,
                'google_business_profile_location_title' => $locationTitle,
            ])->save();
        }

        $reviews = $this->fetchReviews($connection, $locationResourceName);

        Log::info('Google Business Profile location reviews fetched.', [
            'location_resource_name' => $locationResourceName,
            'reviews_found' => count($reviews),
        ]);

        $reviewRows = [];

        foreach ($reviews as $review) {
            $reviewRows[] = $this->buildReviewRow(
                review: $review,
                locationName: $canonicalLocationName,
                locationTitle: $locationTitle,
                dealershipId: $dealership?->id,
                syncedAt: $syncedAt
            );
        }

        $this->persistReviewRows($reviewRows);
        $this->pruneMissingReviews($canonicalLocationName, $reviewRows);

        return count($reviewRows);
    }

    private function syncSingleDealership(
        GoogleBusinessProfileConnection $connection,
        array $account,
        Dealership $dealership,
        Carbon $syncedAt,
        Collection $mappedDealerships
    ): int {
        if ($this->shouldSkipSalamancaDealership($dealership)) {
            Log::info('Google Business Profile dealership sync skipped because the dealership itself refers to Salamanca.', [
                'dealership_id' => $dealership->id,
                'dealership_name' => $dealership->name,
            ]);

            return 0;
        }

        [$locationName, $locationTitle] = $this->resolveLocationForDealership($connection, $account, $dealership);
        $canonicalLocationName = $this->canonicalGoogleLocationKey($locationName);

        if ($this->shouldSkipSalamancaDealership($dealership) || $this->shouldSkipSalamancaLocation($locationName, $locationTitle)) {
            Log::info('Google Business Profile dealership sync skipped because it refers to Salamanca.', [
                'dealership_id' => $dealership->id,
                'dealership_name' => $dealership->name,
                'location_name' => $locationName,
                'location_title' => $locationTitle,
            ]);

            return 0;
        }

        $locationResourceName = $this->buildLocationResourceName($account['name'], $locationName);

        Log::info('Google Business Profile dealership sync started.', [
            'dealership_id' => $dealership->id,
            'dealership_name' => $dealership->name,
            'location_name' => $locationName,
            'location_title' => $locationTitle,
            'location_resource_name' => $locationResourceName,
        ]);

        $mappedDealerships->push($dealership->id);

        $dealership->forceFill([
            'google_business_profile_location_name' => $canonicalLocationName,
            'google_business_profile_location_title' => $locationTitle,
        ])->save();

        $reviews = $this->fetchReviews($connection, $locationResourceName);

        Log::info('Google Business Profile dealership reviews fetched.', [
            'dealership_id' => $dealership->id,
            'location_resource_name' => $locationResourceName,
            'reviews_found' => count($reviews),
        ]);

        $reviewRows = [];

        foreach ($reviews as $review) {
            $reviewRows[] = $this->buildReviewRow(
                review: $review,
                locationName: $canonicalLocationName,
                locationTitle: $locationTitle,
                dealershipId: $dealership->id,
                syncedAt: $syncedAt
            );
        }

        $this->persistReviewRows($reviewRows);
        $this->pruneMissingReviews($canonicalLocationName, $reviewRows);

        return count($reviewRows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviewRows
     */
    private function persistReviewRows(array $reviewRows): void
    {
        if ($reviewRows === []) {
            return;
        }

        foreach (array_chunk($reviewRows, 250) as $index => $chunk) {
            $chunk = array_map(
                fn (array $reviewRow): array => $this->normalizeReviewRowForPersistence($reviewRow),
                $chunk
            );

            DB::transaction(function () use ($chunk): void {
                GoogleBusinessProfileReview::query()->upsert(
                    $chunk,
                    ['review_name'],
                    [
                        'dealership_id',
                        'location_name',
                        'location_title',
                        'reviewer_name',
                        'reviewer_photo_url',
                        'rating',
                        'comment',
                        'reply_name',
                        'reply_comment',
                        'reply_updated_at',
                        'review_created_at',
                        'review_updated_at',
                        'synced_at',
                        'raw_payload',
                        'updated_at',
                    ]
                );
            });

            Log::info('Google Business Profile review chunk persisted.', [
                'chunk_index' => $index + 1,
                'chunk_count' => count($chunk),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviewRows
     */
    private function pruneMissingReviews(string $locationName, array $reviewRows): void
    {
        $reviewNames = collect($reviewRows)
            ->pluck('review_name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = GoogleBusinessProfileReview::query()
            ->where('location_name', $locationName);

        if ($reviewNames === []) {
            $deletedCount = $query->delete();

            Log::info('Google Business Profile review rows pruned for empty location sync.', [
                'location_name' => $locationName,
                'deleted_count' => $deletedCount,
            ]);

            return;
        }

        $deletedCount = $query
            ->whereNotIn('review_name', $reviewNames)
            ->delete();

        Log::info('Google Business Profile review rows pruned after sync.', [
            'location_name' => $locationName,
            'deleted_count' => $deletedCount,
            'remaining_review_count' => count($reviewNames),
        ]);
    }

    /**
     * @param  array<int, string>  $currentLocationNames
     */
    private function pruneReviewsForMissingGoogleLocations(array $currentLocationNames): void
    {
        if ($currentLocationNames === []) {
            Log::warning('Google Business Profile sync skipped stale review pruning because no Google locations were found.');

            return;
        }

        $deletedCount = GoogleBusinessProfileReview::query()
            ->withoutSalamanca()
            ->whereNotIn('location_name', $currentLocationNames)
            ->delete();

        Log::info('Google Business Profile stale reviews pruned after full sync.', [
            'deleted_count' => $deletedCount,
            'current_location_count' => count($currentLocationNames),
        ]);
    }

    /**
     * @param  array<string, mixed>  $reviewRow
     * @return array<string, mixed>
     */
    private function normalizeReviewRowForPersistence(array $reviewRow): array
    {
        if (array_key_exists('raw_payload', $reviewRow)) {
            $reviewRow['raw_payload'] = json_encode(
                $this->sanitizeUtf8Recursive($reviewRow['raw_payload']),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        }

        return $reviewRow;
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function sanitizeReviewPayload(array $review): array
    {
        if (array_key_exists('comment', $review)) {
            $review['comment'] = $this->sanitizeReviewComment($review['comment']);
        }

        return $review;
    }

    private function sanitizeReviewComment(mixed $comment): ?string
    {
        if (! is_string($comment)) {
            return $this->sanitizeUtf8String($this->stringOrNull($comment));
        }

        $comment = trim($comment);

        if ($comment === '') {
            return null;
        }

        $parts = preg_split('/\R*\(Translated by Google\)\R*/i', $comment, 2);
        $cleanComment = trim((string) ($parts[0] ?? $comment));

        return $this->sanitizeUtf8String($cleanComment === '' ? null : $cleanComment);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeUtf8Recursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $payload[$key] = $this->sanitizeUtf8String($value);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitizeUtf8Recursive($value);
            }
        }

        return $payload;
    }

    private function sanitizeUtf8String(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($sanitized === false) {
            $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } else {
            $sanitized = trim($sanitized);
        }

        return $sanitized === '' ? null : $sanitized;
    }

    /**
     * @param  array<int, string>  $locationTerms
     */
    private function shouldSkipSalamancaLocation(?string $locationName, ?string $locationTitle, array $locationTerms = []): bool
    {
        if ($this->containsSalamanca($locationName) || $this->containsSalamanca($locationTitle)) {
            return true;
        }

        foreach ($locationTerms as $term) {
            if ($this->containsSalamanca($term)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkipSalamancaDealership(Dealership $dealership): bool
    {
        return $this->containsSalamanca($dealership->name)
            || $this->containsSalamanca($dealership->google_business_profile_location_name)
            || $this->containsSalamanca($dealership->google_business_profile_location_title);
    }

    private function containsSalamanca(?string $value): bool
    {
        $normalized = $this->normalizeText($value);

        return $normalized !== '' && str_contains($normalized, 'salamanca');
    }

    /**
     * @param  array<int, string>  $locationTerms
     */
    private function resolveDealershipForLocation(string $locationName, ?string $locationTitle, array $locationTerms = []): ?Dealership
    {
        $canonicalLocationName = $this->canonicalGoogleLocationKey($locationName);

        if ($canonicalLocationName !== '' && Schema::hasColumn('dealerships', 'google_business_profile_location_name')) {
            $dealership = Dealership::query()
                ->withoutSalamanca()
                ->get()
                ->first(function (Dealership $candidate) use ($canonicalLocationName): bool {
                    return $this->canonicalGoogleLocationKey($candidate->google_business_profile_location_name) === $canonicalLocationName;
                });

            if ($dealership) {
                return $dealership;
            }
        }

        if ($this->shouldTreatLocationAsStandalone($locationName, $locationTitle, $locationTerms)) {
            Log::info('Google Business Profile location kept as standalone because it looks like a renting location.', [
                'location_name' => $locationName,
                'location_title' => $locationTitle,
                'location_terms' => $locationTerms,
            ]);

            return null;
        }

        $explicitDealership = $this->resolveExplicitDealershipMatch([
            $locationName,
            $locationTitle,
            ...$locationTerms,
        ]);

        if ($explicitDealership) {
            return $explicitDealership;
        }

        $matchCandidates = collect(array_filter([
            $locationTitle,
            ...$locationTerms,
        ], fn ($value): bool => is_string($value) && trim($value) !== ''))->unique()->values();

        foreach ($matchCandidates as $candidateValue) {
            $normalizedCandidateValue = $this->normalizeText($candidateValue);

            if ($normalizedCandidateValue === '') {
                continue;
            }

            $dealerships = Dealership::query()
                ->withoutSalamanca()
                ->get()
                ->values();

            $bestMatch = null;
            $bestScore = 0;

            foreach ($dealerships as $candidate) {
                $score = $this->scoreDealershipMatch($candidate, $normalizedCandidateValue);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $candidate;
                }
            }

            if ($bestMatch && $bestScore >= 70) {
                Log::info('Google Business Profile dealership matched by location hint.', [
                    'location_name' => $locationName,
                    'location_title' => $locationTitle,
                    'matched_value' => $candidateValue,
                    'dealership_id' => $bestMatch->id,
                    'dealership_name' => $bestMatch->name,
                    'match_score' => $bestScore,
                ]);

                return $bestMatch;
            }
        }

        Log::warning('Google Business Profile location could not be matched to a dealership.', [
            'location_name' => $locationName,
            'location_title' => $locationTitle,
            'location_terms' => $locationTerms,
        ]);

        return null;
    }

    /**
     * @param  array<int, string>  $locationTerms
     */
    private function shouldTreatLocationAsStandalone(string $locationName, ?string $locationTitle, array $locationTerms = []): bool
    {
        $normalizedValues = array_values(array_filter(array_map(
            fn ($value): string => $this->normalizeText($value),
            array_filter([
                $locationName,
                $locationTitle,
                ...$locationTerms,
            ], fn ($value): bool => is_string($value) && trim($value) !== '')
        )));

        foreach ($normalizedValues as $normalizedValue) {
            if (str_contains($normalizedValue, 'renting')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string|null>  $locationValues
     */
    private function resolveExplicitDealershipMatch(array $locationValues): ?Dealership
    {
        $normalizedValues = array_values(array_unique(array_filter(array_map(
            fn ($value): string => $this->normalizeText($value),
            $locationValues
        ))));

        if ($normalizedValues === []) {
            return null;
        }

        $explicitRules = [
            'malagacentro' => ['malagacentro'],
        ];

        foreach ($explicitRules as $needle => $candidateNames) {
            $matchedValue = collect($normalizedValues)->first(fn (string $value): bool => str_contains($value, $needle));

            if (! $matchedValue) {
                continue;
            }

            $dealership = Dealership::query()
                ->withoutSalamanca()
                ->get()
                ->first(function (Dealership $candidate) use ($candidateNames): bool {
                    $candidateNormalizedName = $this->normalizeText($candidate->name);

                    return in_array($candidateNormalizedName, $candidateNames, true);
                });

            if ($dealership) {
                Log::info('Google Business Profile dealership matched by explicit rule.', [
                    'matched_value' => $matchedValue,
                    'dealership_id' => $dealership->id,
                    'dealership_name' => $dealership->name,
                ]);

                return $dealership;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveLocationForDealership(
        GoogleBusinessProfileConnection $connection,
        array $account,
        Dealership $dealership
    ): array {
        $locationName = $this->stringOrNull($dealership->google_business_profile_location_name);
        $locationTitle = $this->stringOrNull($dealership->google_business_profile_location_title) ?? $dealership->name;

        if ($locationName) {
            return [$locationName, $locationTitle];
        }

        $locations = $this->fetchLocations($connection, $account['name']);

        foreach ($locations as $location) {
            $candidateLocationName = $this->stringOrNull(data_get($location, 'name'));
            $candidateLocationTitle = $this->extractLocationTitle($location);
            $candidateLocationTerms = $this->extractLocationMatchTerms($location);

            if ($this->shouldSkipSalamancaLocation($candidateLocationName, $candidateLocationTitle, $candidateLocationTerms)) {
                continue;
            }

            $matchedDealership = $candidateLocationName
                ? $this->resolveDealershipForLocation($candidateLocationName, $candidateLocationTitle, $candidateLocationTerms)
                : null;

            if ($matchedDealership?->is($dealership)) {
                return [
                    $candidateLocationName,
                    $candidateLocationTitle ?? $dealership->name,
                ];
            }
        }

        throw new RuntimeException(sprintf(
            'No se ha podido resolver la ubicaciÃ³n de Google para %s.',
            $dealership->name
        ));
    }

    /**
     * @return array<int, string>
     */
    private function extractLocationSegments(?string $locationTitle): array
    {
        $locationTitle = trim((string) $locationTitle);

        if ($locationTitle === '') {
            return [];
        }

        $segments = preg_split('/\s*\|\|\s*|\s*\|\s*|\s*-\s*|\/|·/', $locationTitle) ?: [];

        return array_values(array_filter(array_map('trim', $segments), fn (string $segment): bool => $segment !== ''));
    }

    private function extractLocationTitle(array $location): ?string
    {
        return $this->stringOrNull(
            data_get($location, 'title')
                ?? data_get($location, 'locationName')
                ?? data_get($location, 'storeCode')
                ?? data_get($location, 'name')
        );
    }

    /**
     * @return array<int, string>
     */
    private function extractLocationMatchTerms(array $location): array
    {
        $terms = [
            data_get($location, 'storefrontAddress.locality'),
            data_get($location, 'storefrontAddress.administrativeArea'),
            data_get($location, 'storefrontAddress.postalCode'),
            data_get($location, 'storeCode'),
            data_get($location, 'title'),
        ];

        return array_values(array_unique(array_filter(array_map(
            fn ($value): ?string => $this->stringOrNull($value),
            $terms
        ))));
    }

    private function scoreDealershipMatch(Dealership $candidate, string $normalizedValue): int
    {
        $bestScore = 0;
        $bestSpecificity = 0;

        $candidateName = $candidate->name;
        $normalizedCandidate = $this->normalizeText($candidateName);

        if ($normalizedCandidate === '') {
            return 0;
        }

        if (
            $normalizedCandidate === $normalizedValue
            || str_contains($normalizedValue, $normalizedCandidate)
            || str_contains($normalizedCandidate, $normalizedValue)
        ) {
            $bestScore = 90;
            $bestSpecificity = strlen($normalizedCandidate);
        }

        foreach ($this->extractDealershipNameSegments($candidateName) as $candidateSegment) {
            $normalizedSegment = $this->normalizeText($candidateSegment);

            if ($normalizedSegment === '') {
                continue;
            }

            $score = 0;

            if (
                $normalizedSegment === $normalizedValue
                || str_contains($normalizedValue, $normalizedSegment)
                || str_contains($normalizedSegment, $normalizedValue)
            ) {
                $score = 100;
            } else {
                $distance = levenshtein($normalizedSegment, $normalizedValue);
                $longestLength = max(strlen($normalizedSegment), strlen($normalizedValue));

                if ($longestLength >= 6 && $distance <= 1) {
                    $score = 95;
                } elseif ($longestLength >= 10 && $distance <= 2) {
                    $score = 85;
                }
            }

            if ($score > 0) {
                $specificity = strlen($normalizedSegment);

                if ($score > $bestScore || ($score === $bestScore && $specificity > $bestSpecificity)) {
                    $bestScore = $score;
                    $bestSpecificity = $specificity;
                }
            }
        }

        return $bestScore;
    }

    /**
     * @return array<int, string>
     */
    private function extractDealershipNameSegments(?string $dealershipName): array
    {
        $dealershipName = trim((string) $dealershipName);

        if ($dealershipName === '') {
            return [];
        }

        $segments = preg_split('/\s*\|\|\s*|\s*\|\s*|\s*-\s*|\/|·/', $dealershipName) ?: [];

        return array_values(array_filter(array_map('trim', $segments), fn (string $segment): bool => $segment !== ''));
    }

    private function ratingToInteger(mixed $rating): ?int
    {
        if (is_numeric($rating)) {
            return (int) $rating;
        }

        return match (Str::upper(trim((string) $rating))) {
            'ONE' => 1,
            'TWO' => 2,
            'THREE' => 3,
            'FOUR' => 4,
            'FIVE' => 5,
            default => null,
        };
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeText(?string $value): string
    {
        $value = Str::ascii(Str::lower(trim((string) $value)));

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    public function canonicalGoogleLocationKey(?string $locationName): string
    {
        $locationName = trim((string) $locationName);

        if ($locationName === '') {
            return '';
        }

        if (preg_match('#(?:accounts/[^/]+/)?locations/([^/]+)$#i', $locationName, $matches) === 1) {
            return Str::lower('locations/' . $matches[1]);
        }

        return Str::lower($locationName);
    }

    private function ensureRequiredTablesExist(): void
    {
        if (
            ! Schema::hasTable('google_business_profile_connections')
            || ! Schema::hasTable('google_business_profile_reviews')
            || ! Schema::hasTable('google_business_profile_monthly_snapshots')
        ) {
            throw new RuntimeException('Faltan las tablas de reseñas de Google Business Profile. Ejecuta las migraciones antes de conectar.');
        }
    }
}
