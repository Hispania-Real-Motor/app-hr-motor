<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnreachErrorEventRequest;
use App\Models\EnreachErrorEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

class InternalEnreachErrorEventController extends Controller
{
    public function store(StoreEnreachErrorEventRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $sourceEventId = $validated['source_event_id'];
        $sourceWorkflow = $validated['source_workflow'];

        $existingEvent = EnreachErrorEvent::query()
            ->where('source_event_id', $sourceEventId)
            ->where('source_workflow', $sourceWorkflow)
            ->first();

        if ($existingEvent !== null) {
            return response()->json([
                'status' => 'duplicate',
                'event_id' => $existingEvent->getKey(),
            ]);
        }

        try {
            $event = EnreachErrorEvent::query()->create([
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
        } catch (UniqueConstraintViolationException) {
            $event = EnreachErrorEvent::query()
                ->where('source_event_id', $sourceEventId)
                ->where('source_workflow', $sourceWorkflow)
                ->firstOrFail();

            return response()->json([
                'status' => 'duplicate',
                'event_id' => $event->getKey(),
            ]);
        }

        return response()->json([
            'status' => 'created',
            'event_id' => $event->getKey(),
        ], 201);
    }
}
