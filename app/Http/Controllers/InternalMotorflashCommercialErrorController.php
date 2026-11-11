<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMotorflashCommercialErrorRequest;
use App\Models\MotorflashCommercialErrorEvent;
use App\Models\MotorflashCommercialErrorGroup;
use App\Models\MotorflashCommercialErrorReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InternalMotorflashCommercialErrorController extends Controller
{
    public function store(StoreMotorflashCommercialErrorRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $created = DB::transaction(function () use ($validated): bool {
                if (MotorflashCommercialErrorReceipt::query()
                    ->where('source_event_id', $validated['source_event_id'])
                    ->where('source_workflow', $validated['source_workflow'])
                    ->lockForUpdate()
                    ->exists()) {
                    return false;
                }

                $normalizedEmail = MotorflashCommercialErrorEvent::normalizeEmail($validated['searched_email']);

                MotorflashCommercialErrorGroup::query()->firstOrCreate([
                    'error_type' => $validated['error_type'],
                    'searched_email_normalized' => $normalizedEmail,
                ], [
                    'status' => MotorflashCommercialErrorGroup::STATUS_OPEN,
                ]);

                MotorflashCommercialErrorReceipt::query()->create([
                    'source_event_id' => $validated['source_event_id'],
                    'source_workflow' => $validated['source_workflow'],
                    'received_at' => now(),
                ]);

                MotorflashCommercialErrorEvent::query()->create([
                    'error_type' => $validated['error_type'],
                    'searched_email' => $validated['searched_email'],
                    'searched_email_normalized' => $normalizedEmail,
                    'occurred_at' => CarbonImmutable::parse($validated['occurred_at']),
                    'source_event_id' => $validated['source_event_id'],
                    'source_workflow' => $validated['source_workflow'],
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        return response()->json([
            'status' => $created ? 'created' : 'duplicate',
        ], $created ? 201 : 200);
    }
}
