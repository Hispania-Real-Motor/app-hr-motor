<?php

use App\Models\Dealership;
use App\Models\GoogleBusinessProfileReview;
use App\Services\GoogleBusinessProfileReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('google-business-profile:debug-duplicate-reviews {--limit=20}', function (GoogleBusinessProfileReviewService $service) {
    $this->line('Inspeccionando grupos duplicados de Google Business Profile...');

    Artisan::call('google-business-profile:inspect-duplicate-reviews', [
        '--limit' => $this->option('limit'),
    ], $this->output);

    return self::SUCCESS;
})->purpose('Muestra duplicados canonicos de reseñas de Google Business Profile.');

Artisan::command('google-business-profile:cleanup-duplicate-reviews', function (GoogleBusinessProfileReviewService $service) {
    $this->line('Iniciando limpieza de duplicados de Google Business Profile...');

    $deletedCount = $service->dedupeDuplicateReviewRows();

    $this->info(sprintf('Limpieza completada. Filas duplicadas eliminadas: %d.', $deletedCount));

    return self::SUCCESS;
})->purpose('Elimina las reseñas duplicadas de Google Business Profile.');

Artisan::command('google-business-profile:reviews {month} {location} {--sync}', function (GoogleBusinessProfileReviewService $service) {
    $month = trim((string) $this->argument('month'));
    $location = trim((string) $this->argument('location'));

    if ($month === '' || ! preg_match('/^(0[1-9]|1[0-2])-\d{2}$/', $month)) {
        $this->error('El mes debe tener el formato MM-YY.');

        return self::FAILURE;
    }

    if ($location === '') {
        $this->error('La delegación es obligatoria.');

        return self::FAILURE;
    }

    if ($this->option('sync')) {
        $dealership = Dealership::query()
            ->withoutSalamanca()
            ->where('google_business_profile_location_title', $location)
            ->orWhere('name', $location)
            ->first();

        if ($dealership) {
            if ($service->getConnection()) {
                $this->line(sprintf('Sincronizando desde Google la delegación "%s"...', $dealership->name));
                $service->sync($dealership);
            } else {
                $this->warn('La conexión de Google Business Profile no está configurada. Se mostrará solo la base de datos local.');
            }
        } else {
            $this->warn(sprintf('No se ha encontrado una delegación enlazada para "%s". Se mostrará solo la base de datos local.', $location));
        }
    }

    $monthStart = Carbon::createFromFormat('m-y', $month)->startOfMonth();
    $monthEnd = $monthStart->copy()->endOfMonth();

    $reviews = GoogleBusinessProfileReview::query()
        ->withoutSalamanca()
        ->where('location_title', $location)
        ->whereBetween('review_created_at', [$monthStart, $monthEnd])
        ->orderBy('review_created_at')
        ->orderBy('id')
        ->get([
            'id',
            'review_name',
            'reviewer_name',
            'rating',
            'comment',
            'reply_comment',
            'review_created_at',
            'review_updated_at',
            'location_name',
            'location_title',
        ]);

    if ($reviews->isEmpty()) {
        $this->info(sprintf('No se han encontrado reseñas para "%s" en el mes %s.', $location, $month));

        return self::SUCCESS;
    }

    $this->line(sprintf('Delegación: %s', $location));
    $this->line(sprintf('Mes: %s', $month));
    $this->line(sprintf('Reseñas: %d', $reviews->count()));
    $this->line(sprintf('Media: %.2f', round((float) $reviews->avg('rating'), 2)));
    $this->newLine();

    foreach ($reviews as $review) {
        $this->line(sprintf(
            '%s | %s | %s | %s | %s',
            $review->review_created_at?->format('Y-m-d H:i:s') ?? '-',
            $review->rating ?? '-',
            $review->reviewer_name ?? 'Anónimo',
            $review->review_name ?? '-',
            $review->comment ?? '-'
        ));
    }

    return self::SUCCESS;
})->purpose('Muestra las reseñas de una delegación y un mes concretos, opcionalmente sincronizando antes desde Google Business Profile.');

Artisan::command('google-business-profile:latest-reviews {location} {--limit=10}', function (GoogleBusinessProfileReviewService $service) {
    $location = trim((string) $this->argument('location'));
    $limit = (int) $this->option('limit');

    if ($location === '') {
        $this->error('La delegacion es obligatoria.');

        return self::FAILURE;
    }

    if ($limit <= 0) {
        $this->error('El limite debe ser mayor que cero.');

        return self::FAILURE;
    }

    $dealership = Dealership::query()
        ->withoutSalamanca()
        ->where('google_business_profile_location_title', $location)
        ->orWhere('name', $location)
        ->first();

    if (! $dealership) {
        $this->error(sprintf('No se ha encontrado una delegacion exacta para "%s".', $location));

        return self::FAILURE;
    }

    try {
        $payload = $service->fetchLatestReviewsForDealership($dealership, $limit);
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $reviews = collect($payload['reviews'] ?? []);

    $this->line(sprintf('Delegacion: %s', $payload['location_title'] ?? $payload['dealership_name']));
    $this->line(sprintf('Limite: %d', $limit));
    $this->line(sprintf('Resenas: %d', $reviews->count()));
    $this->line(sprintf('Media: %.2f', $reviews->count() > 0 ? round((float) $reviews->avg('rating'), 2) : 0));
    $this->newLine();

    foreach ($reviews as $index => $review) {
        $this->line(sprintf(
            '%d. %s | %s | %s | %s',
            $index + 1,
            $review['review_created_at'] ?? '-',
            $review['rating'] ?? '-',
            $review['reviewer_name'] ?? 'Anonimo',
            str_replace(["\r", "\n"], ' ', trim((string) ($review['comment'] ?? '-')))
        ));
    }

    return self::SUCCESS;
})->purpose('Muestra las ultimas reseñas de Google Business Profile para una delegacion exacta.');

Schedule::command('salesforce:sync-leaderboard')->everyTenMinutes();
Schedule::command('google-business-profile:sync-reviews')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('chat:purge-expired-messages')
    ->dailyAt('05:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
Schedule::command('motorflash:mailbox:poll')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('motorflash_mailbox.enabled')
        && (app()->environment('production') || (bool) config('motorflash_mailbox.allow_non_production')));
