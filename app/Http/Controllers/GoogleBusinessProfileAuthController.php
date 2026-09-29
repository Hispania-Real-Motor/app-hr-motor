<?php

namespace App\Http\Controllers;

use App\Services\GoogleBusinessProfileReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleBusinessProfileAuthController extends Controller
{
    private const PERMISSION = 'reviews.google.manage';

    public function redirect(Request $request)
    {
        $this->authorizeAccess();

        $redirectUri = route('google-business-profile.callback', [], true);

        if (blank(config('services.google_business_profile.client_id')) || blank($redirectUri)) {
            return redirect()
                ->to(\App\Filament\Pages\GoogleBusinessProfileConnectionPage::getUrl())
                ->with('error', 'Faltan variables de entorno de Google Business Profile. Revisa GOOGLE_BUSINESS_PROFILE_CLIENT_ID y GOOGLE_BUSINESS_PROFILE_REDIRECT_URI.');
        }

        $state = Str::random(40);
        $request->session()->put('google_business_profile_oauth_state', $state);

        $queryParameters = [
            'response_type' => 'code',
            'client_id' => config('services.google_business_profile.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => config('services.google_business_profile.scope'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        if (filled(config('services.google_business_profile.login_hint'))) {
            $queryParameters['login_hint'] = config('services.google_business_profile.login_hint');
        }

        $query = http_build_query($queryParameters);

        return redirect()->away(config('services.google_business_profile.authorize_url') . '?' . $query);
    }

    public function callback(Request $request, GoogleBusinessProfileReviewService $service)
    {
        $this->authorizeAccess();

        $expectedState = (string) $request->session()->pull('google_business_profile_oauth_state');
        $receivedState = (string) $request->string('state');

        abort_unless(
            filled($expectedState) && filled($receivedState) && hash_equals($expectedState, $receivedState),
            403
        );

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $service->saveAuthorizationCodeTokens($request->string('code')->toString());
        } catch (Throwable $exception) {
            Log::error('Google Business Profile OAuth callback failed.', [
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->to(\App\Filament\Pages\GoogleBusinessProfileConnectionPage::getUrl())
                ->with('error', 'No se ha podido completar la conexion OAuth con Google Business Profile.');
        }

        return redirect()
            ->to(\App\Filament\Pages\GoogleBusinessProfileConnectionPage::getUrl())
            ->with('success', 'Google Business Profile conectado correctamente. La sincronizacion se ejecutara en el siguiente ciclo o al pulsar Sincronizar ahora.');
    }

    private function authorizeAccess(): void
    {
        abort_unless(app_user_has_admin_permission(auth()->user(), self::PERMISSION), 403);
    }
}
