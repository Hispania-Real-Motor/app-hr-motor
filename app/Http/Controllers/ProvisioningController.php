<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessProvisioningRequestJob;
use App\Models\Dealership;
use App\Models\ProvisioningRequest;
use App\Models\ProvisioningStep;
use App\Models\User;
use App\Support\UserCreationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class ProvisioningController extends Controller
{
    public function index(): View
    {
        $this->authorizeModule();
        $requests = ProvisioningRequest::query()->with(['requestedBy', 'steps'])->latest()->paginate(15);
        return view('tools.altas-bajas.index', compact('requests'));
    }

    public function create(Request $request): View
    {
        $this->authorizeModule();
        return view('tools.altas-bajas.create', $this->formData($request->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeModule();
        $user = $request->user();
        $baseRole = app_visible_role($user) === User::ROLE_ADMIN ? $request->input('role') : User::ROLE_USER;
        $extraRole = filled($request->input('extra_role')) ? $request->input('extra_role') : null;
        $validated = $request->merge(['role' => $baseRole])->validate(UserCreationRules::for($baseRole, $extraRole));

        try {
            $provisioningRequest = DB::transaction(function () use ($validated, $user): ProvisioningRequest {
                $created = ProvisioningRequest::query()->create([
                    'type' => ProvisioningRequest::TYPE_ONBOARDING,
                    'status' => ProvisioningRequest::STATUS_PENDING,
                    'requested_by_user_id' => $user->id,
                    'employee_data' => $validated,
                ]);
                $platforms = collect(config('provisioning.platforms', []))
                    ->filter(fn (array $platform): bool => ($platform['enabled'] ?? false))
                    ->keys();
                foreach ($platforms as $platform) {
                    $created->steps()->create(['platform' => $platform, 'status' => ProvisioningStep::STATUS_PENDING]);
                }
                return $created;
            });
        } catch (Throwable $exception) {
            report($exception);
            return back()->withInput()->with('error', 'No se ha podido crear la solicitud de alta.');
        }

        ProcessProvisioningRequestJob::dispatch($provisioningRequest->id);
        return redirect()->route('tools.altas-bajas.show', $provisioningRequest);
    }

    public function show(ProvisioningRequest $provisioningRequest): View
    {
        $this->authorizeModule();
        $provisioningRequest->load(['requestedBy', 'steps']);
        return view('tools.altas-bajas.show', compact('provisioningRequest'));
    }

    public function status(ProvisioningRequest $provisioningRequest): JsonResponse
    {
        $this->authorizeModule();
        $provisioningRequest->load(['steps']);
        return response()->json([
            'status' => $provisioningRequest->status,
            'progress' => $provisioningRequest->progress,
            'steps' => $provisioningRequest->steps->map(fn (ProvisioningStep $step): array => [
                'platform' => $step->platform,
                'label' => config("provisioning.platforms.{$step->platform}.label", $step->platform),
                'status' => $step->status,
                'status_label' => $step->status_label,
                'message' => $step->message,
                'started_at' => $step->started_at?->toIso8601String(),
                'finished_at' => $step->finished_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function authorizeModule(): void
    {
        abort_unless(app_can_access_tools_management(auth()->user()), 403);
    }

    private function formData(User $user): array
    {
        $availableBaseRoles = app_visible_role($user) === User::ROLE_ADMIN
            ? array_keys(User::baseRoleLabels())
            : [User::ROLE_USER];

        return [
            'availableBaseRoles' => $availableBaseRoles,
            'availableExtraRoles' => array_keys(User::extraRoleLabels()),
            'availableDealerships' => Dealership::query()->orderBy('name')->get(),
        ];
    }
}
