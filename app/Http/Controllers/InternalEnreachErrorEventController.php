<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnreachErrorEventRequest;
use App\Models\EnreachErrorEvent;
use App\Models\EnreachErrorEventReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InternalEnreachErrorEventController extends Controller
{
    public function store(StoreEnreachErrorEventRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $sourceEventId = $validated['source_event_id'];
        $sourceWorkflow = $validated['source_workflow'];

        try {
            $created = DB::transaction(function () use ($validated, $sourceEventId, $sourceWorkflow): bool {
                if (EnreachErrorEventReceipt::query()
                    ->where('source_event_id', $sourceEventId)
                    ->where('source_workflow', $sourceWorkflow)
                    ->exists()) {
                    return false;
                }

                $existingEvent = EnreachErrorEvent::query()
                    ->where('source_event_id', $sourceEventId)
                    ->where('source_workflow', $sourceWorkflow)
                    ->first();

                EnreachErrorEventReceipt::query()->create([
                    'source_event_id' => $sourceEventId,
                    'source_workflow' => $sourceWorkflow,
                    'received_at' => now(),
                ]);

                if ($existingEvent !== null) {
                    return false;
                }

                EnreachErrorEvent::query()->create([
                    'error_type' => $validated['error_type'],
                    'searched_phone' => $validated['searched_phone'],
                    'occurred_at' => CarbonImmutable::parse($validated['occurred_at']),
                    'direction' => $validated['direction'] === 'IN'
                        ? EnreachErrorEvent::DIRECTION_INCOMING
                        : EnreachErrorEvent::DIRECTION_OUTGOING,
                    'other_party_phone' => $validated['other_party_phone'] ?? null,
                    'source_event_id' => $sourceEventId,
                    'source_workflow' => $sourceWorkflow,
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
