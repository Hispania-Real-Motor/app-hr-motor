<?php

use App\Http\Controllers\AdminContentLogController;
use App\Http\Controllers\AdminBulletinLogController;
use App\Http\Controllers\AdminConversationAccessLogController;
use App\Http\Controllers\AdminChatRetentionLogController;
use App\Http\Controllers\AdminChatRetentionHoldController;
use App\Http\Controllers\AdminChatGroupLogController;
use App\Http\Controllers\AdminPermissionLogController;
use App\Http\Controllers\AdminPolicyAcceptanceLogController;
use App\Http\Middleware\EnsureReviewsAccess;
use App\Http\Middleware\EnsureCurriculaAccess;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\AdminNotificationLogController;
use App\Http\Controllers\GoogleBusinessProfileAuthController;
use App\Http\Controllers\RoleViewerController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\DealershipController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CompanyChatController;
use App\Http\Controllers\CommercialCommissionsController;
use App\Http\Controllers\CurriculumsController;
use App\Http\Controllers\FeedbackReportController;
use App\Http\Controllers\ItTicketController;
use App\Http\Controllers\TicketsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TablonController;
use App\Http\Controllers\SalesforceAuthController;
use App\Http\Controllers\SalesforceLeaderboardSyncController;
use App\Http\Controllers\UserController;
use App\Models\MonthlyMagazineSetting;
use App\Models\SalesLeaderboardEntry;
use App\Models\User;
use App\Services\LeaderboardTrendService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/reset-password', function () {
    return redirect()->route('password.request');
});

Route::middleware('auth')->group(function () {
    Route::view('/legal', 'legal')->name('legal');

    Route::get('/mi-perfil', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/mi-perfil/comisiones', [CommercialCommissionsController::class, 'showOwn'])
        ->name('profile.commissions');
    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/notificaciones/resumen', [NotificationController::class, 'summary'])->name('notifications.summary');
    Route::delete('/notificaciones', [NotificationController::class, 'destroyUnread'])->name('notifications.destroy-unread');
    Route::get('/notificaciones/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda.index');
    Route::get('/agenda/usuarios/{user}', [UserController::class, 'show'])->name('agenda.users.show');
    Route::get('/agenda/contactos/{contact}', [ContactController::class, 'show'])->name('agenda.contacts.show');
    Route::get('/tablon', [TablonController::class, 'index'])->name('tablon.index');
    Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');
    Route::get('/leaderboard/ventas', [LeaderboardController::class, 'sales'])->name('leaderboard.sales');
    Route::get('/leaderboard/compras', [LeaderboardController::class, 'purchases'])->name('leaderboard.purchases');
    Route::get('/leaderboard/coches', [LeaderboardController::class, 'vehicles'])->name('leaderboard.vehicles');
        Route::get('/delegaciones', function () {
            return redirect()->to(\App\Filament\Resources\Dealerships\DealershipResource::getUrl());
        })->middleware('role:admin,gestor')->name('dealerships.index');
    Route::get('/delegaciones/{dealership}', [DealershipController::class, 'show'])
        ->whereNumber('dealership')
        ->name('dealerships.show');
    Route::get('/delegaciones/exportar-csv', [DealershipController::class, 'exportCsv'])
        ->name('dealerships.export-csv');
    Route::get('/usuarios/{user}', [UserController::class, 'show'])
        ->whereNumber('user')
        ->name('users.show');
    Route::get('/usuarios/exportar-csv', [UserController::class, 'exportCsv'])
        ->middleware('role:admin,gestor')
        ->name('users.export-csv');
    Route::get('/web', function () {
        return redirect()->away(config('portal.links.tools.web_hr_motor'));
    })->name('tools.web');

    Route::get('/informes', function () {
        abort_unless(app_can_access_hr_reports(request()->user()), 403);

        return view('tools.embedded', [
            'url' => 'https://informes.app.hrmotor.com/informes/',
            'title' => 'Informes HR',
        ]);
    })->name('tools.informes');

    Route::middleware(EnsureCurriculaAccess::class)->group(function () {
        Route::get('/curriculums', [CurriculumsController::class, 'index'])->name('curriculums.index');
        Route::post('/curriculums', [CurriculumsController::class, 'store'])->name('curriculums.store');
        Route::get('/curriculums/{analysis}', [CurriculumsController::class, 'show'])
            ->whereNumber('analysis')
            ->name('curriculums.show');
        Route::delete('/curriculums/{analysis}', [CurriculumsController::class, 'destroy'])
            ->whereNumber('analysis')
            ->name('curriculums.destroy');
        Route::get('/curriculums/{analysis}/estado', [CurriculumsController::class, 'status'])
            ->whereNumber('analysis')
            ->name('curriculums.status');
    });

    Route::get('/chat', [CompanyChatController::class, 'index'])->name('chat.beta');
    Route::get('/chat/politica', [CompanyChatController::class, 'policyStatus'])->name('chat.beta.policy.status');
    Route::post('/chat/politica/aceptar', [CompanyChatController::class, 'acceptPolicy'])->name('chat.beta.policy.accept');
    Route::post('/chat/conversations/{conversation}/mensajes', [CompanyChatController::class, 'storeMessage'])
        ->whereNumber('conversation')
        ->name('chat.beta.messages.store');
    Route::patch('/chat/conversations/{conversation}/mensajes/{message}', [CompanyChatController::class, 'updateMessage'])
        ->whereNumber('conversation')
        ->whereNumber('message')
        ->name('chat.beta.messages.update');
    Route::delete('/chat/conversations/{conversation}/mensajes/{message}', [CompanyChatController::class, 'destroyMessage'])
        ->whereNumber('conversation')
        ->whereNumber('message')
        ->name('chat.beta.messages.destroy');
    Route::middleware('throttle:chat-api')->group(function (): void {
        Route::get('/chat/conversations/{conversation}/mensajes', [CompanyChatController::class, 'messages'])
            ->whereNumber('conversation')
            ->name('chat.beta.messages.index');
        Route::get('/chat/resumen', [CompanyChatController::class, 'summary'])->name('chat.beta.summary');
    });
    Route::get('/chat/conversations/{conversation}/mensajes/{message}/adjuntos/{attachmentIndex}', [CompanyChatController::class, 'downloadAttachment'])
        ->whereNumber('conversation')
        ->whereNumber('message')
        ->whereNumber('attachmentIndex')
        ->name('chat.beta.attachments.show');
    Route::post('/chat/favoritos/{user}', [CompanyChatController::class, 'toggleFavorite'])
        ->whereNumber('user')
        ->name('chat.beta.favorites.toggle');

    Route::post('/visor-roles', [RoleViewerController::class, 'store'])->name('role-viewer.store');
    Route::delete('/visor-roles', [RoleViewerController::class, 'destroy'])->name('role-viewer.destroy');
    Route::post('/feedback', [FeedbackReportController::class, 'store'])->name('feedback.store');
    Route::get('/tickets', [TicketsController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/informes', [TicketsController::class, 'reports'])->name('tickets.reports');
    Route::get('/tickets/{itTicket}', [TicketsController::class, 'show'])
        ->whereNumber('itTicket')
        ->name('tickets.show');
    Route::post('/tickets/{itTicket}/tool', [TicketsController::class, 'updateTool'])
        ->whereNumber('itTicket')
        ->name('tickets.tool.update');
    Route::post('/tickets/{itTicket}/requester', [TicketsController::class, 'updateRequester'])
        ->whereNumber('itTicket')
        ->name('tickets.requester.update');
    Route::post('/tickets/{itTicket}/priority', [TicketsController::class, 'updatePriority'])
        ->whereNumber('itTicket')
        ->name('tickets.priority.update');
    Route::post('/tickets/{itTicket}/assign', [TicketsController::class, 'assign'])
        ->whereNumber('itTicket')
        ->name('tickets.assign');
    Route::delete('/tickets/{itTicket}', [TicketsController::class, 'destroy'])
        ->whereNumber('itTicket')
        ->name('tickets.destroy');
    Route::post('/tickets/{itTicket}/mensajes', [TicketsController::class, 'reply'])
        ->whereNumber('itTicket')
        ->name('tickets.messages.store');
    Route::post('/tickets/{itTicket}/reapertura', [TicketsController::class, 'requestReopen'])
        ->whereNumber('itTicket')
        ->name('tickets.reopen.request');
    Route::post('/tickets/{itTicket}/reabrir', [TicketsController::class, 'reopen'])
        ->whereNumber('itTicket')
        ->name('tickets.reopen');
    Route::post('/tickets/{itTicket}/clausurar', [TicketsController::class, 'permanentlyClose'])
        ->whereNumber('itTicket')
        ->name('tickets.permanently-close');
    Route::get('/incidencias-it', [ItTicketController::class, 'index'])->name('it-tickets.index');
    Route::get('/incidencias-it/crear', [ItTicketController::class, 'create'])->name('it-tickets.create');
    Route::post('/incidencias-it', [ItTicketController::class, 'store'])->name('it-tickets.store');

    Route::get('/integraciones/google-business-profile/conectar', [GoogleBusinessProfileAuthController::class, 'redirect'])
        ->name('google-business-profile.connect');
    Route::get('/integraciones/google-business-profile/callback', [GoogleBusinessProfileAuthController::class, 'callback'])
        ->name('google-business-profile.callback');

    Route::middleware(EnsureReviewsAccess::class)->group(function () {
        Route::get('/resenas', [ReviewController::class, 'index'])->name('reviews.index');
        Route::get('/resenas/todas', [ReviewController::class, 'all'])->name('reviews.all');
        Route::get('/resenas/informes', [ReviewController::class, 'reports'])->name('reviews.reports');
        Route::get('/resenas/informes/mensuales', [ReviewController::class, 'reportsMonthly'])->name('reviews.reports.monthly');
        Route::get('/resenas/informes/mensuales/comparativa-delegaciones', [ReviewController::class, 'reportsMonthlyComparison'])->name('reviews.reports.monthly.comparison');
        Route::get('/resenas/informes/mensuales/comparativa-delegaciones-roscos', [ReviewController::class, 'reportsMonthlyComparisonRoscos'])->name('reviews.reports.monthly.roscos');
        Route::get('/resenas/informes/semestrales', [ReviewController::class, 'reportsSemiannual'])->name('reviews.reports.semiannual');
        Route::get('/resenas/informes/semestrales/graficas-comparativas', [ReviewController::class, 'reportsSemiannualCharts'])->name('reviews.reports.semiannual.charts');
        Route::get('/resenas/delegacion/{dealership}', [ReviewController::class, 'show'])
            ->whereNumber('dealership')
            ->name('reviews.show');
        Route::get('/resenas/ubicacion/{locationKey}', [ReviewController::class, 'location'])
            ->where('locationKey', '[A-Za-z0-9\-_]+')
            ->name('reviews.location');
        Route::post('/resenas/sincronizar/{dealership?}', [ReviewController::class, 'refresh'])
            ->whereNumber('dealership')
            ->name('reviews.refresh');
        Route::post('/resenas/reply/{review}', [ReviewController::class, 'reply'])
            ->whereNumber('review')
            ->name('reviews.reply');
    });

    Route::get('/', function (LeaderboardTrendService $trendService) {
        $authUser = request()->user();
        $visibleRole = app_visible_role($authUser);
        $isDirectSalesforceRole = in_array($visibleRole, [User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER], true);
        $isExternalWebUser = in_array($visibleRole, [User::ROLE_LEGAL, User::ROLE_GUARANTEES, User::ROLE_ADMINISTRATION], true);
        $salesforceUrl = app_salesforce_url_for($authUser);
        $salesforceLabel = $isDirectSalesforceRole ? 'Salesforce' : 'Salesforce comunidad';
        $callCenterSalesforceUrl = 'https://hrmotor.lightning.force.com';
        $itSupportUrl = app_it_support_url_for($authUser);
        $webHrMotorUrl = config('portal.links.tools.web_hr_motor');
        $defaultToolImage = asset('images/tools/tareas-asignadas.webp');

        $buttonSections = [
            [
                'title' => 'Herramientas generales',
                'buttons' => [
                    [
                        'label' => 'DocuSign',
                        'url' => config('portal.links.tools.docusign'),
                        'image' => asset('images/tools/docusign.webp'),
                    ],
                    [
                        'label' => 'OneDrive',
                        'url' => config('portal.links.tools.onedrive'),
                        'image' => asset('images/tools/onedrive.webp'),
                    ],
                    [
                        'label' => 'Web HR Motor',
                        'url' => $webHrMotorUrl,
                        'image' => asset('images/tools/hrmotor.webp'),
                        'open_in_new_tab' => true,
                    ],
                    [
                        'label' => 'Dataprius',
                        'url' => 'https://dataprius.net/hrmotor',
                        'image' => asset('images/tools/dataprius.webp'),
                    ],
                    [
                        'label' => 'ChatGPT',
                        'url' => 'https://chatgpt.com/',
                        'image' => asset('images/tools/chatgpt.webp'),
                    ],
                    [
                        'label' => 'CaixaBank',
                        'url' => 'https://www.caixabank.es/particular/home/particulares_es.html',
                        'image' => asset('images/tools/caixabank.webp'),
                    ],
                    [
                        'label' => 'Calculadora Vacaciones',
                        'url' => 'https://calculadoravacaciones.com/',
                        'image' => asset('images/tools/calculadora.webp'),
                    ],
                    [
                        'label' => 'Docusign',
                        'url' => 'https://apps.docusign.com/send/documents?view=sent&type=envelopes',
                        'image' => asset('images/tools/docusign.webp'),
                    ],
                    [
                        'label' => 'iLovePDF',
                        'url' => 'https://www.ilovepdf.com/es',
                        'image' => asset('images/tools/ilovepdf.webp'),
                    ],
                    [
                        'label' => 'Mi IP',
                        'url' => 'https://www.cual-es-mi-ip.net/',
                        'image' => asset('images/tools/ip.webp'),
                    ],
                    [
                        'label' => 'Google Drive',
                        'url' => app_user_has_any_role($authUser, [User::ROLE_RENTING])
                            ? 'https://drive.google.com/drive/folders/1mMTTZxsiAyasIgeEcE6wvefIHEaPH1aPq'
                            : config('portal.links.tools.google_drive'),
                        'image' => asset('images/tools/drive.webp'),
                    ],
                    [
                        'label' => 'Calcular IVA',
                        'url' => 'https://calcular-iva.net/',
                        'image' => asset('images/tools/calcular-iva.webp'),
                    ],
                    [
                        'label' => 'Tareas asignadas',
                        'url' => 'https://axiumsoluciones-my.sharepoint.com/:x:/r/personal/carlos_jimenez_hrmotor_es/_layouts/15/guestaccess.aspx?e=4%3AMLgEPu&at=9&share=IQCamQKzRFtvRryS0Dmv_eAtAYXxXXXxBKlQYJlfbB3-4WI',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Canva',
                        'url' => app_user_has_any_role($authUser, [User::ROLE_RENTING])
                            ? 'https://www.canva.com/folder/FAFxRzs7QD0'
                            : 'https://www.canva.com/',
                        'image' => asset('images/tools/canva.webp'),
                    ],
                    [
                        'label' => 'ChatGPT',
                        'url' => 'https://chatgpt.com/',
                        'image' => asset('images/tools/chatgpt.webp'),
                    ],
                    [
                        'label' => 'Envato',
                        'url' => 'https://app.envato.com/',
                        'image' => asset('images/tools/envato.webp'),
                    ],
                    [
                        'label' => 'Trustpilot',
                        'url' => 'https://es.trustpilot.com',
                        'image' => asset('images/tools/trustpilot.webp'),
                    ],
                    [
                        'label' => 'Brevo',
                        'url' => 'https://www.brevo.com/es/landing/products/?utm_source=adwords_brand&utm_medium=lastclick&utm_content=SendinBlue&utm_extension=&utm_term=brevo&utm_matchtype=e&utm_campaign=20027374472&utm_network=g&km_adid=656177194310&km_adposition=&km_device=c&utm_adgroupid=148424525436&gad_source=1&gad_campaignid=20027374472&gclid=CjwKCAjw46HPBhAMEiwASZpLRHPpa5cY5OVSvZ1nzM0F4-M6bkKkUJzkDHI18bUAHrlCCmLxFKCkxxoC9BAQAvD_BwE',
                        'image' => asset('images/tools/brevo.webp'),
                    ],
                    [
                        'label' => 'Sage',
                        'url' => config('portal.links.tools.sage'),
                        'image' => asset('images/tools/Sage.webp'),
                    ],
                    [
                        'label' => 'Contact Center Motorflash',
                        'url' => 'https://callcenter.motorflash.com/',
                        'image' => asset('images/tools/contact-center-motorflash.webp'),
                    ],
                    [
                        'label' => 'Salesforce comunidad',
                        'url' => 'https://hrmotor.my.site.com/hrmotorcommunity/s/login/',
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'HR Renting',
                        'url' => 'https://hrrenting.com/renting/',
                        'image' => asset('images/tools/hrrenting.webp'),
                    ],
                    [
                        'label' => 'Flit2GO',
                        'url' => 'https://manager.flit2go.com/#/s/vehicles/contracts',
                        'image' => asset('images/tools/flit2go.webp'),
                    ],
                    [
                        'label' => 'Salesforce',
                        'url' => app_user_has_any_role($authUser, [User::ROLE_RENTING])
                            ? 'https://hrmotor.my.salesforce.com'
                            : $callCenterSalesforceUrl,
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'Axesor',
                        'url' => 'https://login.axesor.es/account/login?ReturnUrl=%2Fconnect%2Fauthorize%2Fcallback%3Fclient_id%3DWarsImplicitSTS%26redirect_uri%3Dhttps%253A%252F%252Fwww.axesor.es%252F%26response_type%3Did_token%2520token%26scope%3Dopenid%2520profile%2520email%2520BackOffice%2520ApiGestoria%2520ApiRestAxesor%26state%3DOpenIdConnect.AuthenticationProperties%253D9n7EF3VQALVdy7LAzGo41YYYFMLfXwPDfhrFyrwZhMjSE8ON0eSx2X8NTwlDRiVrYmyEswRsBCCPd5iDoRO12x3KpIX9kSGw-owqmKbBBYQ9Lx1TkiKcBQGDgu3uvn5fszKqo5FLGmheZsOnhyng3nWh34X3BvoTLHU5myd0rpiC9KB2LJXUgEr4ShNkwcTJ44X__S1ryzofJfTfs2AO7XXOV22vMOkIQr_-SJuS9sM%26response_mode%3Dform_post%26nonce%3D638996572989165092.NmJjOWQxNDUtZTVjOC00MzE5LWEyNDMtMDlmZGQ1YjRkNzEyNDEwZTdhYWQtMTZmMC00MTY5LWFmNjgtYzQ5NmRiOTRmYmY4%26acr_values%3Dtenant%253Dmonitoriza%26prompt%3Dlogin%26x-client-SKU%3DID_NET472%26x-client-ver%3D7.6.2.0%26suppressed_prompt%3Dlogin',
                        'image' => asset('images/tools/axesor.webp'),
                    ],
                    [
                        'label' => 'Incofisa',
                        'url' => 'https://incofisa-digital.web.app/incofisadigital/auth/login',
                        'image' => asset('images/tools/incofisa.webp'),
                    ],
                    [
                        'label' => 'Caixa',
                        'url' => 'https://loc26.caixabank.es/GPeticiones;WebLogicSession=1YvYZ9P4si6mlmZiPgbjyPGiP_gJIAjWNv3wsLDgRlvsL3cjV7U9!118285288!866939666',
                        'image' => asset('images/tools/caixabank.webp'),
                    ],
                    [
                        'label' => 'Drive Financiaciones',
                        'url' => 'https://docs.google.com/spreadsheets/d/1fPr7brHdculz7zGGGL9kND306EvpaRrT/edit?gid=1992833401#gid=1992833401',
                        'image' => asset('images/tools/drive.webp'),
                    ],
                    [
                        'label' => 'Caixa todas operaciones',
                        'url' => 'https://autos.caixabankpc.com/apw5/fncWebPrescriptores/VerTodasOperaciones.do',
                        'image' => asset('images/tools/caixabank.webp'),
                    ],
                    [
                        'label' => 'BBVA Financiaciones',
                        'url' => 'https://operaciones.bbvaconsumerfinance.es/finanzianet/pro/vulcanize/index.html',
                        'image' => asset('images/tools/bbva.webp'),
                    ],
                    [
                        'label' => 'Banco Santander',
                        'url' => 'https://www.bancosantander.es/particulares',
                        'image' => asset('images/tools/santander.webp'),
                    ],
                    [
                        'label' => 'Pagos',
                        'url' => 'https://www.bbva.es/empresas.html',
                        'image' => asset('images/tools/bbva.webp'),
                    ],
                    [
                        'label' => 'Wiuse',
                        'url' => 'https://wiuse.net/',
                        'image' => asset('images/tools/wiuse.webp'),
                    ],
                    [
                        'label' => 'Caixabank',
                        'url' => 'https://www.caixabank.es/particular/home/particulares_es.html',
                        'image' => asset('images/tools/caixabank.webp'),
                    ],
                    [
                        'label' => 'Excel Recambios',
                        'url' => 'https://docs.google.com/spreadsheets/d/181wJehtjfuXl0fS-Rhbnol4SS7tRJPGazu8IUJVpXj0/edit?gid=0#gid=0',
                        'image' => asset('images/tools/excel.webp'),
                    ],
                    [
                        'label' => 'VISA',
                        'url' => 'https://docs.google.com/spreadsheets/d/11gJdeRYSWrRX7Uej5JK_g0gUOmD5vvSM/edit?gid=1895952355#gid=1895952355',
                        'image' => asset('images/tools/visa.webp'),
                    ],
                    [
                        'label' => 'Microsoft Teams',
                        'url' => 'https://teams.microsoft.com/v2/',
                        'image' => asset('images/tools/teams.webp'),
                    ],
                    [
                        'label' => app_user_has_any_role($authUser, [User::ROLE_CAPTADOR]) ? 'Salesforce comunidad' : $salesforceLabel,
                        'url' => app_user_has_any_role($authUser, [User::ROLE_CAPTADOR])
                            ? 'https://hrmotor.my.site.com/hrmotorcommunity/s/'
                            : $salesforceUrl,
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'Salesforce',
                        'url' => 'https://hrmotor.lightning.force.com/lightning',
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'AD360',
                        'url' => 'https://www.ad360.es/',
                        'image' => asset('images/tools/ad360.webp'),
                    ],
                    [
                        'label' => 'Milanuncios',
                        'url' => 'https://www.milanuncios.com/',
                        'image' => asset('images/tools/milanuncios.webp'),
                    ],
                    [
                        'label' => 'Wallapop',
                        'url' => 'https://es.wallapop.com/wall',
                        'image' => asset('images/tools/wallapop.webp'),
                    ],
                    [
                        'label' => 'Cochesnet',
                        'url' => 'https://www.coches.net/',
                        'image' => asset('images/tools/cochesnet.webp'),
                    ],
                    [
                        'label' => 'Lendismart',
                        'url' => 'https://hrmotor.lendismart.com/app/search-applications',
                        'image' => asset('images/tools/lendismart.webp'),
                    ],
                    [
                        'label' => 'DGT',
                        'url' => 'https://sede.dgt.gob.es/es/multas/identificacion-del-conductor-de-tu-vehiculo/',
                        'image' => asset('images/tools/dgt.webp'),
                    ],
                    [
                        'label' => 'REG',
                        'url' => 'https://reg.redsara.es/es/',
                        'image' => asset('images/tools/registro-electronico-general.webp'),
                    ],
                    [
                        'label' => 'DEHú',
                        'url' => 'https://dehu.redsara.es/es/public',
                        'image' => asset('images/tools/dehu.webp'),
                    ],
                    [
                        'label' => 'Sede Judicial Electrónica',
                        'url' => 'https://sedejudicial.justicia.es/-/lexnet',
                        'image' => asset('images/tools/sede-judicial-electronica.webp'),
                    ],
                    [
                        'label' => 'BOE',
                        'url' => 'https://www.boe.es/',
                        'image' => asset('images/tools/boe.webp'),
                    ],
                    [
                        'label' => 'My Mutua',
                        'url' => config('portal.links.tools.my_mutua'),
                        'image' => asset('images/tools/mutua.webp'),
                    ],
                    [
                        'label' => 'Formación Comerciales',
                        'url' => config('portal.links.tools.formacion_comerciales'),
                        'image' => asset('images/tools/logo-formacion-cuadrado.webp'),
                    ],
                    [
                        'label' => 'Coches de cortesía',
                        'url' => 'https://docs.google.com/spreadsheets/d/1_fiL4TyclqhOtkBijSqaxAcc8c6XmmSy/edit?pli=1&gid=590253332#gid=590253332',
                        'image' => asset('images/tools/coches-cortesia.webp'),
                    ],
                    [
                        'label' => 'Citas garantías HR',
                        'url' => 'https://docs.google.com/spreadsheets/d/16uY7SOshvkNKOti7BfLfIgWkJNyNM__8/edit?pli=1&gid=2129562621#gid=2129562621',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Pólizas activas Caser OK',
                        'url' => 'https://docs.google.com/spreadsheets/d/1Q6iDEW_dhR47MwVR3t-omI-zV-c0W72x/edit?pli=1&gid=714492361#gid=714492361',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Envío documentación',
                        'url' => 'https://docs.google.com/spreadsheets/d/1ZN-ej468hjsZM-Aqb5Mgdk_hocXSkkcM/edit?pli=1&gid=1194646592#gid=1194646592',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Ventas caídas y excesos 2026',
                        'url' => 'https://docs.google.com/spreadsheets/d/1Ovm_KJr2JAumJ1KngBT3rYopFYDjk2W4/edit?pli=1&gid=36299908#gid=36299908',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Seguro coche cortesía',
                        'url' => 'https://docs.google.com/spreadsheets/d/15w1ELzuEQsG3zq79y2w_6t_BRD8uZjx8/edit?pli=1&gid=2118661665#gid=2118661665',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Salesforce',
                        'url' => $callCenterSalesforceUrl,
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'Onlogist',
                        'url' => 'https://portal.onlogist.com/#myBuynows',
                        'image' => asset('images/tools/onlogist.webp'),
                    ],
                    [
                        'label' => 'FaciliteaCoches',
                        'url' => 'https://www.admin.faciliteacoches.com/login',
                        'image' => asset('images/tools/stock-facilitea.webp'),
                    ],
                    [
                        'label' => 'Tickelia',
                        'url' => 'https://cloud.tickelia.com/web/#/login',
                        'image' => asset('images/tools/tickelia.webp'),
                    ],
                    [
                        'label' => 'COC Online',
                        'url' => 'https://www.coc-online.com/es',
                        'image' => asset('images/tools/coc-online.webp'),
                    ],
                    [
                        'label' => 'Euro COC',
                        'url' => 'https://www.eurococ.eu/it/coc-vin-verifica//?checkvin=VF3CU9HP0KY038844',
                        'image' => asset('images/tools/euro-coc.webp'),
                    ],
                    [
                        'label' => 'Encheres VO',
                        'url' => 'https://pro.encheres-vo.com/portail.html',
                        'image' => asset('images/tools/encheres-vo.webp'),
                    ],
                    [
                        'label' => 'UPS',
                        'url' => 'https://www.ups.com/track?loc=es_ES&requester=ST/',
                        'image' => asset('images/tools/ups.webp'),
                    ],
                    [
                        'label' => 'Occident',
                        'url' => 'https://cliente.occident.com/overall-position',
                        'image' => asset('images/tools/occident.webp'),
                    ],
                    [
                        'label' => 'Facilitea',
                        'url' => 'https://www.admin.faciliteacoches.com/admin/orders',
                        'image' => asset('images/tools/stock-facilitea.webp'),
                    ],
                    [
                        'label' => 'ChatGPT',
                        'url' => 'https://chatgpt.com/',
                        'image' => asset('images/tools/chatgpt.webp'),
                    ],
                        [
                            'label' => 'Stock Facilitea',
                            'url' => 'https://stockyleads.motorflash.com/login.php?urlBack=%2Findex.php',
                            'image' => asset('images/tools/stock-facilitea.webp'),
                        ],
                    [
                        'label' => 'Enreach',
                        'url' => 'https://omnichannel.masvoz.es',
                        'image' => asset('images/tools/enreach.webp'),
                    ],
                    [
                        'label' => 'GarantiAuto',
                        'url' => 'https://www.gsonline.es/login',
                        'image' => asset('images/tools/garantiauto.webp'),
                    ],
                    [
                        'label' => 'Coches.net',
                        'url' => 'https://www.coches.net/',
                        'image' => asset('images/tools/cochesnet.webp'),
                    ],
                    [
                        'label' => 'Norauto',
                        'url' => 'https://www.fleetvalidation.com/login?redirect=%2F1031%2F',
                        'image' => asset('images/tools/norauto.webp'),
                    ],
                ],
            ],
            [
                'title' => 'Comunicación',
                'buttons' => [
                    [
                        'label' => 'WhatsApp Web',
                        'url' => config('portal.links.tools.whatsapp_web'),
                        'image' => asset('images/tools/whatsapp.webp'),
                    ],
                    [
                        'label' => 'Enreach',
                        'url' => config('portal.links.tools.enreach'),
                        'image' => asset('images/tools/enreach.webp'),
                    ],
                    [
                        'label' => 'Webmail',
                        'url' => config('portal.links.tools.webmail'),
                        'image' => asset('images/tools/webmail.webp'),
                    ],
                ],
            ],
        ];

        $buttonSections = collect($buttonSections)
            ->map(function (array $section) use ($authUser, $salesforceLabel): array {
                if (($section['title'] ?? null) !== 'Herramientas generales') {
                    return $section;
                }

                $commercialButtonLabels = [
                    'DocuSign',
                    $salesforceLabel,
                    'Lendismart',
                    'My Mutua',
                    'Formación Comerciales',
                ];

                $captadorButtonLabels = [
                    'Salesforce comunidad',
                    'Milanuncios',
                    'Wallapop',
                    'Cochesnet',
                ];

                $workshopButtonLabels = [
                    'Salesforce',
                    'AD360',
                ];

                $callCenterGeneralButtonLabels = [
                    'Salesforce',
                    'Salesforce comunidad',
                    'Facilitea',
                    'ChatGPT',
                    'Contact Center Motorflash',
                    'Microsoft Teams',
                    'Stock Facilitea',
                    'Enreach',
                    'GarantiAuto',
                    'Coches.net',
                    'Norauto',
                ];

                $humanResourcesButtonLabels = [
                    'ChatGPT',
                    'CaixaBank',
                    'Unión de Mutuas',
                    'Calculadora Vacaciones',
                    'Docusign',
                    'Sede',
                    'Seguridad Social',
                    'Convenios Colectivos',
                    'Sistema Delta',
                    'Sede SEPE',
                    'iLovePDF',
                    'Sepe',
                    'Sepe usuarios',
                    'Trámites Navarra',
                    'Dehú',
                    'Registro Electrónico',
                    'Mi IP',
                    'Seguridad Social Portal',
                ];

                $rentingButtonLabels = [
                    'Rent2click',
                    'HR Renting',
                    'Flit2GO',
                    'Salesforce',
                    'Axesor',
                    'Incofisa',
                    'Canva',
                    'Google Drive',
                ];

                $logisticsButtonLabels = [
                    'Salesforce',
                    'Onlogist',
                    'FaciliteaCoches',
                    'Tickelia',
                    'COC Online',
                    'Euro COC',
                    'Encheres VO',
                    'UPS',
                    'Occident',
                ];

                $legalButtonLabels = [
                    'Citas garantías HR',
                    'Pólizas activas Caser OK',
                    'Envío documentación',
                    'Ventas caídas y excesos 2026',
                    'Seguro coche cortesía',
                ];

                $legalExcludedButtonLabels = [
                    'Citas garantías HR',
                    'Pólizas activas Caser OK',
                    'Envío documentación',
                    'Ventas caídas y excesos 2026',
                    'Seguro coche cortesía',
                ];

                $legalGeneralButtonLabels = [
                    'Salesforce',
                    'DGT',
                    'REG',
                    'DEHú',
                    'Sede Judicial Electrónica',
                    'BOE',
                ];

                $guaranteesExcludedGeneralButtonLabels = [
                    'Salesforce',
                    'DGT',
                    'REG',
                    'DEHú',
                    'Sede Judicial Electrónica',
                    'BOE',
                ];

                $sparePartsGeneralButtonLabels = [
                    'Wiuse',
                    'Caixabank',
                    'Excel Recambios',
                    'VISA',
                ];

                $financingButtonLabels = [
                    'Caixa',
                    'Drive Financiaciones',
                    'Caixa todas operaciones',
                    'BBVA Financiaciones',
                    'Banco Santander',
                    'Pagos',
                ];

                $financingOtherResourcesLabels = [
                    'Occident',
                    'Soyou',
                    'Lendismart',
                    'BitGest PRO',
                ];

                $financingExcludedGeneralButtonLabels = [
                    'Occident',
                    'Lendismart',
                ];

                $fixedGeneralButtonLabels = [
                    'Sage',
                    'Web HR Motor',
                    'OneDrive',
                ];

                $filteredButtons = collect($section['buttons'] ?? [])
                    ->filter(fn (array $button) => in_array($button['label'] ?? null, ['OneDrive', 'Sage', 'Web HR Motor'], true) || (
                        in_array($button['label'] ?? null, $commercialButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER])
                    ) || (
                        in_array($button['label'] ?? null, $captadorButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_CAPTADOR])
                    ) || (
                        in_array($button['label'] ?? null, $workshopButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_WORKSHOP])
                    ) || (
                        in_array($button['label'] ?? null, $callCenterGeneralButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_CALL_CENTER])
                    ) || (
                        in_array($button['label'] ?? null, $humanResourcesButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_HUMAN_RESOURCES])
                    ) || (
                        ($button['label'] ?? null) === 'Tareas asignadas'
                        && app_user_has_any_role($authUser, [User::ROLE_MARKETING])
                    ) || (
                        in_array($button['label'] ?? null, ['Canva', 'ChatGPT', 'Envato', 'Trustpilot', 'Brevo'], true)
                        && app_user_has_any_role($authUser, [User::ROLE_MARKETING])
                    ) || (
                        in_array($button['label'] ?? null, ['Google Drive', 'Occident', 'Calcular IVA'], true)
                        && app_user_has_any_role($authUser, [User::ROLE_ADMINISTRATION])
                    ) || (
                        ($button['label'] ?? null) === 'Dataprius'
                        && app_user_has_any_role($authUser, [User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER, User::ROLE_WORKSHOP])
                    ) || (
                        in_array($button['label'] ?? null, $legalButtonLabels, true)
                        && (
                            app_user_has_any_role($authUser, [User::ROLE_GUARANTEES])
                            || (
                                app_user_has_any_role($authUser, [User::ROLE_LEGAL])
                                && ! in_array($button['label'] ?? null, $legalExcludedButtonLabels, true)
                            )
                        )
                    ) || (
                        in_array($button['label'] ?? null, $legalGeneralButtonLabels, true)
                        && (
                            app_user_has_any_role($authUser, [User::ROLE_LEGAL])
                            || (
                                app_user_has_any_role($authUser, [User::ROLE_GUARANTEES])
                                && ! in_array($button['label'] ?? null, $guaranteesExcludedGeneralButtonLabels, true)
                            )
                        )
                    ) || (
                        in_array($button['label'] ?? null, $sparePartsGeneralButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_SPARE_PARTS])
                    ) || (
                        in_array($button['label'] ?? null, $financingButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_FINANCING])
                    ) || (
                        in_array($button['label'] ?? null, $financingOtherResourcesLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_FINANCING])
                        && ! in_array($button['label'] ?? null, $financingExcludedGeneralButtonLabels, true)
                    ) || (
                        in_array($button['label'] ?? null, $rentingButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_RENTING])
                    ) || (
                        in_array($button['label'] ?? null, $logisticsButtonLabels, true)
                        && app_user_has_any_role($authUser, [User::ROLE_LOGISTICS])
                    ))
                    ->unique(fn (array $button) => $button['label'] ?? null);

                $section['buttons'] = collect($fixedGeneralButtonLabels)
                    ->map(fn (string $label) => $filteredButtons->firstWhere('label', $label))
                    ->filter()
                    ->merge(
                        $filteredButtons->reject(fn (array $button) => in_array($button['label'] ?? null, $fixedGeneralButtonLabels, true))
                    )
                    ->values()
                    ->all();

                return $section;
            })
            ->all();

        $callCenterResourcesSection = app_user_has_any_role($authUser, [User::ROLE_CALL_CENTER])
            ? [
                'title' => 'Otros recursos',
                'buttons' => [
                    [
                        'label' => 'Coches de cortesía',
                        'url' => 'https://docs.google.com/spreadsheets/d/1_fiL4TyclqhOtkBijSqaxAcc8c6XmmSy/edit?pli=1&gid=590253332#gid=590253332',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Citas garantías HR',
                        'url' => 'https://docs.google.com/spreadsheets/d/16uY7SOshvkNKOti7BfLfIgWkJNyNM__8/edit?pli=1&gid=2129562621#gid=2129562621',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Pólizas activas Caser OK',
                        'url' => 'https://docs.google.com/spreadsheets/d/1Q6iDEW_dhR47MwVR3t-omI-zV-c0W72x/edit?pli=1&gid=714492361#gid=714492361',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Envío documentación',
                        'url' => 'https://docs.google.com/spreadsheets/d/1ZN-ej468hjsZM-Aqb5Mgdk_hocXSkkcM/edit?pli=1&gid=1194646592#gid=1194646592',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Ventas caídas y excesos 2026',
                        'url' => 'https://docs.google.com/spreadsheets/d/1Ovm_KJr2JAumJ1KngBT3rYopFYDjk2W4/edit?pli=1&gid=36299908#gid=36299908',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Seguro coche cortesía',
                        'url' => 'https://docs.google.com/spreadsheets/d/15w1ELzuEQsG3zq79y2w_6t_BRD8uZjx8/edit?pli=1&gid=2118661665#gid=2118661665',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                ],
            ]
            : null;

        $financingOtherResourcesSection = app_user_has_any_role($authUser, [User::ROLE_FINANCING])
            ? [
                'title' => 'Otros recursos',
                'buttons' => [
                    [
                        'label' => 'Occident',
                        'url' => 'https://cliente.occident.com/policies/27201113/B/fleets',
                        'image' => asset('images/tools/occident.webp'),
                    ],
                    [
                        'label' => 'Soyou',
                        'url' => 'https://colabora.soyou.es/#/login',
                        'image' => asset('images/tools/soyou.webp'),
                    ],
                    [
                        'label' => 'Lendismart',
                        'url' => 'https://hrmotor.lendismart.com/app/search-applications',
                        'image' => asset('images/tools/lendismart.webp'),
                    ],
                    [
                        'label' => 'BitGest PRO',
                        'url' => 'https://bitgestprofesionales.com/mi-cuenta/mis-tramites',
                        'image' => asset('images/tools/bitgest.webp'),
                    ],
                ],
            ]
            : null;

        $logisticsResourcesSection = app_user_has_any_role($authUser, [User::ROLE_LOGISTICS])
            ? [
                'title' => 'Hojas de cálculo',
                'buttons' => [
                    [
                        'label' => 'Transporte 24-25',
                        'url' => 'https://docs.google.com/spreadsheets/d/1U2fuEEPgY26ylsoVOrHbVOZ41pQ5N0VvW-MJIffkPI4/edit?pli=1&gid=0#gid=0',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Control Tickelia Internos',
                        'url' => 'https://docs.google.com/spreadsheets/d/1XG7LlCaao9ueoBpGibMGY3t1ka_btB_LnlWqTNuXzhk/edit?gid=0#gid=0',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Docs Extranjeras',
                        'url' => 'https://docs.google.com/spreadsheets/d/1qNlbHsxXN03UXzrWMozhizRSbkzjepN6/edit?gid=903951598#gid=903951598',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Envío Documentación',
                        'url' => 'https://docs.google.com/spreadsheets/d/1ZN-ej468hjsZM-Aqb5Mgdk_hocXSkkcM/edit?gid=1186450835#gid=1186450835',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Cargas Extranjeras',
                        'url' => 'https://docs.google.com/spreadsheets/d/1Y3RcOKWtw7WXYKKtEjDZx8mkz0Gw8OvA/edit?gid=138774793#gid=138774793',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Cargas Nacionales',
                        'url' => 'https://docs.google.com/spreadsheets/d/1gR3Lx9AdZxXVAdLVJYLmChrwsZdojYJD_r5UoLmXUCY/edit?gid=0#gid=0',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Pendientes Crear Salesforce',
                        'url' => 'https://docs.google.com/spreadsheets/d/1vT3jlH_xmBLBPUPrKQnjrzfBXt1QMFHfB0A6EUTG9mI/edit?gid=876360531#gid=876360531',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Vehículos Sin Ubicación',
                        'url' => 'https://docs.google.com/spreadsheets/d/1T3s-ftYq3MvLDU1iIYuo8QGGpGCOKYD5/edit?gid=1273017528#gid=1273017528',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Envío CMR',
                        'url' => 'https://docs.google.com/spreadsheets/d/1GViw3gWAOGdzgGX65z_U08x8IDmMjtYb/edit?gid=1796310716#gid=1796310716',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Control de Vehículos',
                        'url' => 'https://docs.google.com/spreadsheets/d/1x0qBp4M5S_oVDoLlq3EETurRNTlekRyp/edit?gid=1415421642#gid=1415421642',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Hoja de Transporte',
                        'url' => 'https://docs.google.com/spreadsheets/d/1nC6iC2m8kcUlDzaRzAadmUd9LrgoZd_f6fgGMHmc3tY/edit?gid=0#gid=0',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                ],
            ]
            : null;

        $sparePartsResourcesSection = app_user_has_any_role($authUser, [User::ROLE_SPARE_PARTS])
            ? [
                'title' => 'Recambios',
                'buttons' => [
                    [
                        'label' => 'Recambio Fácil',
                        'url' => 'https://www.recambiofacil.com/login/index',
                        'image' => asset('images/tools/recambio-facil.webp'),
                    ],
                    [
                        'label' => 'Top Recambios',
                        'url' => 'https://toprecambios.com/profesional/login.php',
                        'image' => asset('images/tools/top-recambios.webp'),
                    ],
                    [
                        'label' => 'Amazon',
                        'url' => 'https://www.amazon.es/',
                        'image' => asset('images/tools/amazon.webp'),
                    ],
                    [
                        'label' => 'partslink24',
                        'url' => 'https://www.partslink24.com/partslink24/user/login.do',
                        'image' => asset('images/tools/partslink24.webp'),
                    ],
                    [
                        'label' => 'Aliexpress',
                        'url' => 'https://es.aliexpress.com/',
                        'image' => asset('images/tools/aliexpress.webp'),
                    ],
                    [
                        'label' => 'Taros Trade',
                        'url' => 'https://www.tarostrade.es/',
                        'image' => asset('images/tools/taros-trade.webp'),
                    ],
                    [
                        'label' => 'Ovoko',
                        'url' => 'https://ovoko.es/buscar',
                        'image' => asset('images/tools/ovoko.webp'),
                    ],
                    [
                        'label' => 'Ebay',
                        'url' => 'https://www.ebay.es/',
                        'image' => asset('images/tools/ebay.webp'),
                    ],
                    [
                        'label' => 'Lyreco',
                        'url' => 'https://www.lyreco.com/webshop/SPSP/welcome?lc=SPSP',
                        'image' => asset('images/tools/lyreco.webp'),
                    ],
                ],
            ]
            : null;

        $otherResourcesSection = app_user_has_any_role($authUser, [User::ROLE_MARKETING])
            ? [
                'title' => 'Otros recursos',
                'buttons' => [
                    [
                        'label' => 'Jottacloud',
                        'url' => 'https://jottacloud.com/web/search/list/name/WVWZZZAUZLW072077',
                        'image' => asset('images/tools/jottacloud.webp'),
                    ],
                    [
                        'label' => 'CarCutter',
                        'url' => 'https://hub.car-cutter.com/workspace/7ec7b15b-b8a4-47b1-b1b1-5d73d29b341b/',
                        'image' => asset('images/tools/carcutter.webp'),
                    ],
                    [
                        'label' => 'Pendiente editar',
                        'url' => 'https://hrmotor.lightning.force.com/lightning/r/Report/00OQx00000W4PbjMAF/view?queryScope=userFolders',
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'Inventario.pro',
                        'url' => 'https://admin.inventario.pro/login',
                        'image' => asset('images/tools/Inventario-pro.webp'),
                    ],
                    [
                        'label' => 'Informe fotografía',
                        'url' => 'https://docs.google.com/spreadsheets/d/1OMiDqfiTeHWagtXNJagFpNVqxZjuOa5i/edit?gid=374625686#gid=374625686',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                ],
            ]
            : null;

        $humanResourcesOtherResourcesSection = app_user_has_any_role($authUser, [User::ROLE_HUMAN_RESOURCES])
            ? [
                'title' => 'Otros recursos',
                'buttons' => [
                    [
                        'label' => 'Poder Judicial',
                        'url' => 'https://www.poderjudicial.es/cgpj/es/Servicios/Utilidades/Calculo-de-indemnizaciones-por-extincion-de-contrato-de-trabajo/',
                        'image' => asset('images/tools/poder-judicial.webp'),
                    ],
                    [
                        'label' => 'Sepe',
                        'url' => 'https://www.sepe.es:444/ccomunicacto/comunicacto/jsp/menuprincipal.jsp?Comunidad=99&Idioma=14',
                        'image' => asset('images/tools/sepe.webp'),
                    ],
                    [
                        'label' => 'Sepe usuarios',
                        'url' => 'https://sede.sepe.gob.es/GesUsuariosSEDE/GestionUsuariosTrabajaWeb/login_recurso_protegido.do?acceso=empresa&tipoemp=na&CSRFFormToken=null&GAREASONCODE=-1&GARESOURCEID=emp_DCertificadosWeb&GAURI=https://sede.sepe.gob.es/DCertificadosWeb/ActionNavegacion.do%3Faccion%3Dnavegacion&Reason=-1&APPID=emp_DCertificadosWeb&URI=https://sede.sepe.gob.es/DCertificadosWeb/ActionNavegacion.do%3Faccion%3Dnavegacion',
                        'image' => asset('images/tools/sepe.webp'),
                    ],
                    [
                        'label' => 'Trámites Navarra',
                        'url' => 'https://www.navarra.es/es/tramites/on/-/line/Busqueda-de-trabajadores-y-trabajadoras',
                        'image' => asset('images/tools/navarra.webp'),
                    ],
                    [
                        'label' => 'Seguridad Social',
                        'url' => 'https://www.seg-social.es/wps/portal/wss/internet/Inicio',
                        'image' => asset('images/tools/seguridad-social.webp'),
                    ],
                    [
                        'label' => 'Sede',
                        'url' => 'https://sede.mites.gob.es/inicio/detalleProcedimiento/12',
                        'image' => asset('images/tools/sede.webp'),
                    ],
                    [
                        'label' => 'Convenios Colectivos',
                        'url' => 'https://expinterweb.mites.gob.es/regcon/',
                        'image' => asset('images/tools/ministerio-trabajo.webp'),
                    ],
                    [
                        'label' => 'Sistema Delta',
                        'url' => 'https://delta.mites.gob.es/Delta2Web/main/principal.jsp',
                        'image' => asset('images/tools/delta.webp'),
                    ],
                    [
                        'label' => 'Dehú',
                        'url' => 'https://dehu.redsara.es/es/notifications',
                        'image' => asset('images/tools/dehu.webp'),
                    ],
                    [
                        'label' => 'Registro Electrónico',
                        'url' => 'https://reg.redsara.es/es/',
                        'image' => asset('images/tools/registro-electronico-general.webp'),
                    ],
                    [
                        'label' => 'Unión de Mutuas',
                        'url' => 'https://empresas.uniondemutuas.es/portal-empresas/inicio',
                        'image' => asset('images/tools/union-mutuas.webp'),
                    ],
                ],
            ]
            : null;

        $informaticaOtherResourcesSection = app_user_has_any_role($authUser, [User::ROLE_INFORMATION_TECHNOLOGY])
            ? [
                'title' => 'Otros recursos',
                'buttons' => [
                    [
                        'label' => 'Salesforce',
                        'url' => 'https://hrmotor.my.salesforce.com/',
                        'image' => asset('images/tools/salesforce.webp'),
                    ],
                    [
                        'label' => 'Monday',
                        'url' => 'https://hr-motor.monday.com/boards/5088189551/views/39476655',
                        'image' => asset('images/tools/monday.webp'),
                    ],
                    [
                        'label' => 'Google Meet',
                        'url' => 'https://meet.google.com/landing',
                        'image' => asset('images/tools/google-meet.webp'),
                    ],
                    [
                        'label' => 'Bitwarden',
                        'url' => 'https://vault.bitwarden.eu/#/vault',
                        'image' => asset('images/tools/bitwarden.webp'),
                    ],
                    [
                        'label' => 'GitHub',
                        'url' => 'https://github.com/IAhrmotor/app-hr-motor',
                        'image' => asset('images/tools/github.webp'),
                    ],
                    [
                        'label' => 'n8n VPS',
                        'url' => 'https://n8n.hrmotor.com/',
                        'image' => asset('images/tools/n8n-vps.webp'),
                    ],
                    [
                        'label' => 'n8n cloud',
                        'url' => 'https://hrmotor.app.n8n.cloud/projects/FPytsnvV2BfoZtnO/workflows',
                        'image' => asset('images/tools/n8n.webp'),
                    ],
                    [
                        'label' => 'Postgres',
                        'url' => 'https://pgadmin.hrmotor.com/',
                        'image' => asset('images/tools/postgres.webp'),
                    ],
                    [
                        'label' => 'Enreach admin',
                        'url' => 'https://omnichannel.masvoz.es/',
                        'image' => asset('images/tools/enreach-2.webp'),
                    ],
                    [
                        'label' => 'CMS Motorflash',
                        'url' => 'https://hrmotor.pruebasmf.com/mf-admin/dashboard',
                        'image' => asset('images/tools/contact-center-motorflash.webp'),
                    ],
                    [
                        'label' => 'Grafana',
                        'url' => 'https://grafana.hrmotor.com/',
                        'image' => asset('images/tools/grafana.webp'),
                    ],
                    [
                        'label' => 'Twilio',
                        'url' => 'https://console.twilio.com/',
                        'image' => asset('images/tools/twilio.webp'),
                    ],
                    [
                        'label' => 'ElevenLabs',
                        'url' => 'https://elevenlabs.io/app/agents/agents',
                        'image' => asset('images/tools/elevenlabs.webp'),
                    ],
                    [
                        'label' => 'Google Drive',
                        'url' => 'https://drive.google.com/drive/home',
                        'image' => asset('images/tools/drive.webp'),
                    ],
                    [
                        'label' => 'Google Cloud',
                        'url' => 'https://console.cloud.google.com/welcome?project=n8n-credenciales-475007',
                        'image' => asset('images/tools/google-cloud.webp'),
                    ],
                    [
                        'label' => 'Supabase',
                        'url' => 'https://supabase.com/dashboard/org/wsidyogtxwqhxjywhtqs',
                        'image' => asset('images/tools/supabase.webp'),
                    ],
                    [
                        'label' => 'Formación HR Motor',
                        'url' => 'https://formacion.hrmotor.com/',
                        'image' => asset('images/tools/logo-formacion-cuadrado.webp'),
                    ],
                    [
                        'label' => 'Hey Gen',
                        'url' => 'https://app.heygen.com/projects',
                        'image' => asset('images/tools/heygen.webp'),
                    ],
                    [
                        'label' => 'App Scripts',
                        'url' => 'https://script.google.com/home',
                        'image' => asset('images/tools/apps-scripts.webp'),
                    ],
                    [
                        'label' => 'Google Business',
                        'url' => 'https://business.google.com/u/2/reviews',
                        'image' => asset('images/tools/google-business.webp'),
                    ],
                    [
                        'label' => 'Canva',
                        'url' => 'https://www.canva.com/projects',
                        'image' => asset('images/tools/canva.webp'),
                    ],
                    [
                        'label' => 'Freepik',
                        'url' => 'https://www.freepik.es/app',
                        'image' => asset('images/tools/freepik.webp'),
                    ],
                    [
                        'label' => 'OVHCloud',
                        'url' => 'https://manager.eu.ovhcloud.com/#/dedicated/vps/vps-9b2e26eb.vps.ovh.net/dashboard',
                        'image' => asset('images/tools/ovh.webp'),
                    ],
                    [
                        'label' => 'Nginx Proxy Manager',
                        'url' => 'https://npm.hrmotor.com/',
                        'image' => asset('images/tools/nginx.webp'),
                    ],
                    [
                        'label' => 'Motorflash',
                        'url' => 'https://message.motorflash.com/',
                        'image' => asset('images/tools/motorflash-whatsapp.webp'),
                    ],
                    [
                        'label' => 'Dinahosting',
                        'url' => 'https://panel.dinahosting.com/login',
                        'image' => asset('images/tools/dinahosting.webp'),
                    ],
                    [
                        'label' => 'Docusign',
                        'url' => 'https://account.docusign.com/oauth/auth?response_type=code&scope=all%20click.manage%20me_profile%20room_forms%20room_fields%20inproductcommunication_read%20data_explorer_signing_insights%20notary_read%20notary_write%20search_read%20search_write%20webforms_manage%20dtr%20valmod_manage%20spring_read%20spring_write%20signature&client_id=2CC56DC9-4BCD-4B55-8AB0-8BA60BAE1065&redirect_uri=https://apps.docusign.com/authenticate&state=%7b%22widgetId%22:%22%40ds/send%22%2c%22xsrfToken%22:%22IYIogBvwZtz%2BEZviKPqyJlr915WEM8vmFZ14xMfBM5jq%2BBofLnLQDzRb37YARzOUNCSgjxu5e3ub3V0hoZVtzDbLaz7JvwviWui6HrM8DIybiaUP6kTN162yJKtVehy4Vm5nUroRdbTTg0cV3qpGirh%2BH3owkhvZb9GPMPNPPCE%3D%22%2c%22redirectUri%22:%22/send/authentication?back%3D%252Fhome%22%2c%22authTxnId%22:%2217f86b96-dd9d-4f8d-8d26-f741b689b5c2%22%7d',
                        'image' => asset('images/tools/docusign.webp'),
                    ],
                    [
                        'label' => 'Ricoh',
                        'url' => 'https://eu.portal.ricoh-europe.com/es/login?next=%2Fmy-products',
                        'image' => asset('images/tools/ricoh.webp'),
                    ],
                    [
                        'label' => 'Vodafone',
                        'url' => 'https://m.vodafone.es/mves/login',
                        'image' => asset('images/tools/vodafone.webp'),
                    ],
                    [
                        'label' => 'Microsoft 365',
                        'url' => 'https://www.microsoft.com/es-es/microsoft-365/outlook/log-in',
                        'image' => asset('images/tools/microsoft-365.webp'),
                    ],
                    [
                        'label' => 'Supremo',
                        'url' => 'https://www.supremocontrol.com/es/descarga-supremo/windows/',
                        'image' => asset('images/tools/supremo.webp'),
                    ],
                    [
                        'label' => 'Eset',
                        'url' => 'https://identity.eset.com/login/pwd?ReturnUrl=%2Fconnect%2Fauthorize%2Fcallback',
                        'image' => asset('images/tools/eset.webp'),
                    ],
                ],
            ]
            : null;

        $informaticaAccessSection = app_user_has_any_role($authUser, [User::ROLE_INFORMATION_TECHNOLOGY])
            ? [
                'title' => 'Documentos Sistemas',
                'buttons' => [
                    [
                        'label' => 'Contraseñas',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7B84BBE688-98BE-4E4A-A3FC-9D480B44697C%7D&file=herramientas-Redes-y-Sistemas.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Fibras inventario',
                        'url' => 'https://axiumsoluciones-my.sharepoint.com/personal/alberto_cabanyes_hrmotor_es/_layouts/15/AccessDenied.aspx?Source=https%3A%2F%2Faxiumsoluciones%2Dmy%2Esharepoint%2Ecom%2Fpersonal%2Falberto%5Fcabanyes%5Fhrmotor%5Fes%2FDocuments%2FInventario%5FFibras%5Fpor%5FDelegacion%2Exlsx%3Fweb%3D1&correlation=e90a0fa2%2Da0f0%2D0001%2Df6d2%2D2515fd50c549&Type=item&name=c6295303%2D7c4e%2D486e%2D988c%2Da91c960581a5&listItemId=30&listItemUniqueId=64b4b9a5%2Dda32%2D4cc8%2D8997%2D8f224f96a12f',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Licencias Microsoft',
                        'url' => 'https://axiumsoluciones-my.sharepoint.com/personal/alberto_cabanyes_hrmotor_es/_layouts/15/AccessDenied.aspx?Source=https%3A%2F%2Faxiumsoluciones%2Dmy%2Esharepoint%2Ecom%2Fpersonal%2Falberto%5Fcabanyes%5Fhrmotor%5Fes%2FDocuments%2FLicencias%20Microsoft%2Exlsx%3Fweb%3D1&correlation=ec0a0fa2%2D901c%2D0001%2Df6d2%2D26314294ea33&Type=item&name=c6295303%2D7c4e%2D486e%2D988c%2Da91c960581a5&listItemId=28&listItemUniqueId=7a672b81%2D16e8%2D4527%2D89cb%2D3d32b3f1ca2e',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Usuarios ESET',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7B19906C40-001A-41EF-9562-2D7EF77EE71B%7D&file=Eset-Usuarios.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Usuarios Mutua',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7BCFDC3286-5B01-4A89-9AFD-9F70F2282AE0%7D&file=usuarios-mutua.ods&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Cámaras Vigilancia',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7B878D1CEF-B4D2-4EBC-B523-8B63973D03EB%7D&file=C%25u00e1maras-Videovigilancia.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Alias Corporativo',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7BE03B16F1-F72A-49F1-B518-D8165550666E%7D&file=Alias-de-HRCORPORATIVO.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Suscripciones Herramientas',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7BBDEFBFF9-B1A4-4B1F-A33C-4154F9BDF6B5%7D&file=Suscripciones-Herramientas.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Inventario Equipos',
                        'url' => 'https://axiumsoluciones.sharepoint.com/:x:/r/sites/ITportal/_layouts/15/Doc.aspx?sourcedoc=%7BBD76EE28-FCEE-405D-A3DC-4249A61C33BB%7D&file=Inventario-Equipos-HRMOTOR.ods&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Enreach Maestro',
                        'url' => 'https://axiumsoluciones-my.sharepoint.com/:x:/r/personal/g1_departamentoit_hrmotor_es/_layouts/15/Doc.aspx?sourcedoc=%7BABBA7BDB-0644-4B15-BBE0-90E7FD47E8E1%7D&file=ENREACH%20MAESTRO.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                    [
                        'label' => 'Motorflash Maestro',
                        'url' => 'https://axiumsoluciones-my.sharepoint.com/:x:/r/personal/g1_departamentoit_hrmotor_es/_layouts/15/Doc.aspx?sourcedoc=%7B475B2534-F15F-46BF-B879-C5F63DA00BAA%7D&file=Control_Motorflash_Limpio.xlsx&action=default&mobileredirect=true',
                        'image' => asset('images/tools/tareas-asignadas.webp'),
                    ],
                ],
            ]
            : null;

        $legalOtherResourcesSection = app_user_has_any_role($authUser, [User::ROLE_LEGAL])
            ? [
                'title' => 'Otros recursos',
                'buttons' => [
                    [
                        'label' => 'Id Cat Móvil',
                        'url' => 'https://valid.aoc.cat/o/oauth2/auth?response_type=code&client_id=enotum-pro.aoc.cat&scope=autenticacio_usuari&redirect_uri=https%3A%2F%2Fusuari.enotum.cat%2Fvalid%2Fredirect&login_hint=K6Nz6ysRIwx3mULOGk1t9a5K5d2KF4S8nuXruIR3ubt5LqSdL8-jUEtiJFfz8zsrNWKXm3QAeaRmF4k68scapA%3D%3D&codi_ens=1',
                        'image' => asset('images/tools/id-cat-movil.webp'),
                    ],
                    [
                        'label' => 'Multas Euskadi',
                        'url' => 'https://www.euskadi.eus/multa_sancion/multas-trafico/web01-tramite/es/',
                        'image' => asset('images/tools/multas-euskadi.webp'),
                    ],
                    [
                        'label' => 'Diputació Barcelona',
                        'url' => 'https://orgt.diba.cat/ca/Home/selecciomunicipi?areaToReturn=TramitsPagaments&viewToReturn=idconductor&controllerToReturn=IdentificacioConductor&concepteTramit=NO&codiError=WEB00011&parametre=V&IDSessionReturn=3d76d9eb-0a37-4fd7-a1e5-d68840a08ad5&keyModel=modelIDCONDUCTOR_',
                        'image' => asset('images/tools/diputacio-barcelona.webp'),
                    ],
                    [
                        'label' => 'gencat',
                        'url' => 'https://consum.gencat.cat/ca/lagencia/atencio-al-consumidor/resolucio-de-conflictes-de-consum/la-mediacio/index.html#googtrans(ca|es)',
                        'image' => asset('images/tools/gencat.webp'),
                    ],
                    [
                        'label' => 'Oficina Virtual Barcelona',
                        'url' => 'https://seuelectronica.ajuntament.barcelona.cat/oficinavirtual/es',
                        'image' => asset('images/tools/oficina-virtual-barcelona.webp'),
                    ],
                ],
            ]
            : null;

        $videos = [
            [
                'title' => 'Firma electrónica DocuSign',
                'youtube_id' => 'jibss9YUw8M',
            ],
            [
                'title' => 'Vista general Salesforce',
                'youtube_id' => 'ERovlZLtQbE',
            ],
            [
                'title' => 'Proceso Seguros Mutua',
                'youtube_id' => '7zVvaFasavY',
            ],
        ];

        $homeLeaderboardEntries = Schema::hasTable('sales_leaderboard_entries')
            ? SalesLeaderboardEntry::query()
                ->with('user')
                ->when(config('services.salesforce.excluded_leaderboard_user_ids', []) !== [], function ($query) {
                    $query->whereNotIn('salesforce_user_id', config('services.salesforce.excluded_leaderboard_user_ids', []));
                })
                ->orderBy('ranking_position')
                ->limit(10)
                ->get()
            : new Collection();

        $magazine = MonthlyMagazineSetting::current();
        $homeLeaderboardMovements = $trendService->buildMovementMap($homeLeaderboardEntries);

        return view('home', compact('buttonSections', 'otherResourcesSection', 'humanResourcesOtherResourcesSection', 'informaticaOtherResourcesSection', 'informaticaAccessSection', 'legalOtherResourcesSection', 'callCenterResourcesSection', 'sparePartsResourcesSection', 'financingOtherResourcesSection', 'logisticsResourcesSection', 'videos', 'homeLeaderboardEntries', 'homeLeaderboardMovements', 'itSupportUrl', 'magazine'));
    })->name('home');

    Route::get('/videos', function () {
        abort_unless(app_can_access_videos(), 403);

        $videos = [
            [
                'title' => 'Vista general Salesforce',
                'youtube_id' => 'ERovlZLtQbE',
            ],
            [
                'title' => 'Creación y gestión de leads en Salesforce',
                'youtube_id' => 'alcUcfS5S4Y',
            ],
            [
                'title' => 'Conversión del lead en Salesforce',
                'youtube_id' => 'sGzVQy_Wh_4',
            ],
            [
                'title' => 'Solicitud de financiación en Salesforce',
                'youtube_id' => 'eujhUlpJ5Qk',
            ],
            [
                'title' => 'Venta con cambio en Salesforce',
                'youtube_id' => '8gZb9uKgdfE',
            ],
            [
                'title' => 'Proceso de reserva en Salesforce',
                'youtube_id' => 'w-_wLjFHzQE',
            ],
            [
                'title' => 'Seguimiento de las reservas en Salesforce',
                'youtube_id' => 'DiXNmle_pMs',
            ],
            [
                'title' => 'Proceso de tasación en Salesforce',
                'youtube_id' => 'kU4OQHZ8dNc',
            ],
            [
                'title' => 'Firma electrónica DocuSign',
                'youtube_id' => 'jibss9YUw8M',
            ],
            [
                'title' => 'Proceso de entrega en Salesforce',
                'youtube_id' => 'cP8RnVg3V0c',
            ],
            [
                'title' => 'Ficha del vehículo en Salesforce',
                'youtube_id' => '0KF5hs-lBlk',
            ],
            [
                'title' => 'Proceso Seguros Mutua',
                'youtube_id' => '7zVvaFasavY',
            ],
            [
                'title' => 'Gestión de reseñas',
                'youtube_id' => 'cGDOABxjNbs',
            ],
        ];

        return view('videos', compact('videos'));
    })->name('videos');

    Route::get('/empresa', function () {
        return view('empresa.index', [
            'myMapsEmbedUrl' => config('services.company.my_maps_embed_url'),
        ]);
    })->name('empresa.index');

    Route::get('/admin', function () {
        return redirect()->to('/backoffice');
    })->middleware('admin.access')->name('admin.index');

    Route::middleware('role:admin,gestor')->group(function () {

        Route::get('/integraciones/salesforce/conectar', [SalesforceAuthController::class, 'redirect'])->name('salesforce.connect');
        Route::get('/integraciones/salesforce/callback', [SalesforceAuthController::class, 'callback'])->name('salesforce.callback');
        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::get('/usuarios/crear', [UserController::class, 'create'])->name('users.create');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
        Route::post('/usuarios/{user}/reenviar-invitacion', [UserController::class, 'resendInvitation'])
            ->whereNumber('user')
            ->name('users.resend-invitation');
        Route::get('/usuarios/{user}/reenviar-invitacion', [UserController::class, 'resendInvitation'])
            ->whereNumber('user')
            ->name('users.resend-invitation.get');
        Route::patch('/usuarios/{user}/desactivar', [UserController::class, 'disable'])
            ->whereNumber('user')
            ->name('users.disable');
        Route::patch('/usuarios/{user}/reactivar', [UserController::class, 'reactivate'])
            ->whereNumber('user')
            ->name('users.reactivate');
        Route::get('/usuarios/{user}/editar', [UserController::class, 'edit'])
            ->whereNumber('user')
            ->name('users.edit');
        Route::put('/usuarios/{user}', [UserController::class, 'update'])
            ->whereNumber('user')
            ->name('users.update');
        Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])
            ->whereNumber('user')
            ->name('users.destroy');

        Route::get('/delegaciones/crear', [DealershipController::class, 'create'])->name('dealerships.create');
        Route::post('/delegaciones', [DealershipController::class, 'store'])->name('dealerships.store');
        Route::get('/delegaciones/{dealership}/editar', [DealershipController::class, 'edit'])
            ->whereNumber('dealership')
            ->name('dealerships.edit');
        Route::put('/delegaciones/{dealership}', [DealershipController::class, 'update'])
            ->whereNumber('dealership')
            ->name('dealerships.update');
        Route::delete('/delegaciones/{dealership}', [DealershipController::class, 'destroy'])
            ->whereNumber('dealership')
            ->name('dealerships.destroy');
        Route::get('/backoffice/herramientas-tickets/logs/descargar', function () {
            if (! \Illuminate\Support\Facades\Schema::hasTable('ticket_tool_activity_logs')) {
                return response()->streamDownload(function (): void {
                    $output = fopen('php://output', 'w');

                    fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'herramienta', 'color', 'cambios'], ';');

                    fclose($output);
                }, 'logs-herramientas-tickets-pendiente-migracion.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            }

            $action = request()->query('action');
            $dateFrom = request()->query('date_from');
            $dateTo = request()->query('date_to');
            $actorId = request()->query('actor');

            $logs = \App\Models\TicketToolActivityLog::query()
                ->when($action, fn ($query) => $query->where('action', $action))
                ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
                ->when($actorId, fn ($query) => $query->where('actor_user_id', $actorId))
                ->orderByDesc('created_at')
                ->get();

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = $action
                ? "logs-herramientas-tickets-{$action}-{$timestamp}.csv"
                : "logs-herramientas-tickets-{$timestamp}.csv";

            return response()->streamDownload(function () use ($logs): void {
                $output = fopen('php://output', 'w');

                fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'herramienta', 'color', 'cambios'], ';');

                foreach ($logs as $log) {
                    $changes = collect($log->changes ?? [])
                        ->map(function (array $change, string $field): string {
                            return sprintf(
                                '%s: %s -> %s',
                                $field,
                                blank($change['from'] ?? null) ? 'Vacío' : $change['from'],
                                blank($change['to'] ?? null) ? 'Vacío' : $change['to'],
                            );
                        })
                        ->implode(' | ');

                    fputcsv($output, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->action_label,
                        $log->actor_name,
                        $log->actor_email,
                        $log->target_name,
                        $log->target_color,
                        $changes,
                    ], ';');
                }

                fclose($output);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        })->name('backoffice.ticket-tool-logs.export');
        Route::get('/admin/logs/contenidos/descargar', [AdminContentLogController::class, 'export'])->name('admin.content-logs.export');
        Route::get('/admin/logs/politica-aceptacion/descargar', [AdminPolicyAcceptanceLogController::class, 'export'])->name('admin.policy-acceptance-logs.export');
        Route::get('/admin/logs/borrado-chats/descargar', [AdminChatRetentionLogController::class, 'export'])->name('admin.chat-retention-logs.export');

        Route::middleware('role:admin')->group(function () {
            Route::get('/admin/logs/grupos-chat/descargar', [AdminChatGroupLogController::class, 'export'])->name('admin.chat-group-logs.export');
        });
        Route::get('/admin/logs/notificaciones/descargar', [AdminNotificationLogController::class, 'export'])->name('admin.notification-logs.export');
        Route::get('/backoffice/delegaciones/logs/descargar', function (\Illuminate\Http\Request $request) {
            abort_unless(auth()->user()?->role === 'admin', 403);

            $action = $request->query('action');
            $action = is_string($action) && $action !== '' ? $action : null;

            $dateFrom = $request->query('date_from');
            $dateFrom = is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;

            $dateTo = $request->query('date_to');
            $dateTo = is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }

            $actorId = $request->query('actor');
            $actorId = is_numeric($actorId) && (int) $actorId > 0 ? (int) $actorId : null;

            if (! \Illuminate\Support\Facades\Schema::hasTable('dealership_activity_logs')) {
                return response()->streamDownload(function (): void {
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'delegacion', 'cambios'], ';');
                    fclose($output);
                }, 'logs-delegaciones-pendiente-migracion.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            }

            $logs = \App\Models\DealershipActivityLog::query()
                ->when($action, fn ($query) => $query->where('action', $action))
                ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
                ->when($actorId, fn ($query) => $query->where('actor_user_id', $actorId))
                ->orderByDesc('created_at')
                ->get();

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = $action
                ? "logs-delegaciones-{$action}-{$timestamp}.csv"
                : "logs-delegaciones-{$timestamp}.csv";

            return response()->streamDownload(function () use ($logs): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'delegacion', 'cambios'], ';');

                foreach ($logs as $log) {
                    $changes = collect($log->changes ?? [])
                        ->map(function (array $change, string $field): string {
                            return sprintf(
                                '%s: %s -> %s',
                                $field,
                                $change['from'] ?? 'vacío',
                                $change['to'] ?? 'vacío',
                            );
                        })
                        ->implode(' | ');

                    fputcsv($output, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->action_label,
                        $log->actor_name,
                        $log->actor_email,
                        $log->target_name,
                        $changes,
                    ], ';');
                }

                fclose($output);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        })->name('backoffice.dealership-logs.export');
        Route::get('/backoffice/revista/logs/descargar', function (\Illuminate\Http\Request $request) {
            abort_unless(auth()->user()?->role === 'admin', 403);

            $action = $request->query('action');
            $action = is_string($action) && $action !== '' ? $action : null;

            $dateFrom = $request->query('date_from');
            $dateFrom = is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;

            $dateTo = $request->query('date_to');
            $dateTo = is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }

            $actorId = $request->query('actor');
            $actorId = is_numeric($actorId) && (int) $actorId > 0 ? (int) $actorId : null;

            if (! \Illuminate\Support\Facades\Schema::hasTable('monthly_magazine_activity_logs')) {
                return response()->streamDownload(function (): void {
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'revista', 'cambios'], ';');
                    fclose($output);
                }, 'logs-revista-pendiente-migracion.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            }

            $logs = \App\Models\MonthlyMagazineActivityLog::query()
                ->when($action, fn ($query) => $query->where('action', $action))
                ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
                ->when($actorId, fn ($query) => $query->where('actor_user_id', $actorId))
                ->orderByDesc('created_at')
                ->get();

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = $action
                ? "logs-revista-{$action}-{$timestamp}.csv"
                : "logs-revista-{$timestamp}.csv";

            return response()->streamDownload(function () use ($logs): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'revista', 'cambios'], ';');

                foreach ($logs as $log) {
                    $changes = collect($log->changes ?? [])
                        ->map(function (array $change, string $field): string {
                            return sprintf(
                                '%s: %s -> %s',
                                $field,
                                blank($change['from'] ?? null) ? 'Vacío' : $change['from'],
                                blank($change['to'] ?? null) ? 'Vacío' : $change['to'],
                            );
                        })
                        ->implode(' | ');

                    fputcsv($output, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->action_label,
                        $log->actor_name,
                        $log->actor_email,
                        $log->target_name,
                        $changes,
                    ], ';');
                }

                fclose($output);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        })->name('backoffice.monthly-magazine-logs.export');
        Route::get('/backoffice/zonas/logs/descargar', function (\Illuminate\Http\Request $request) {
            abort_unless(auth()->user()?->role === 'admin', 403);

            $action = $request->query('action');
            $action = is_string($action) && $action !== '' ? $action : null;

            $dateFrom = $request->query('date_from');
            $dateFrom = is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;

            $dateTo = $request->query('date_to');
            $dateTo = is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }

            $actorId = $request->query('actor');
            $actorId = is_numeric($actorId) && (int) $actorId > 0 ? (int) $actorId : null;

            if (! \Illuminate\Support\Facades\Schema::hasTable('zone_activity_logs')) {
                return response()->streamDownload(function (): void {
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'zona', 'delegaciones', 'cambios'], ';');
                    fclose($output);
                }, 'logs-zonas-pendiente-migracion.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            }

            $logs = \App\Models\ZoneActivityLog::query()
                ->when($action, fn ($query) => $query->where('action', $action))
                ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
                ->when($actorId, fn ($query) => $query->where('actor_user_id', $actorId))
                ->orderByDesc('created_at')
                ->get();

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = $action
                ? "logs-zonas-{$action}-{$timestamp}.csv"
                : "logs-zonas-{$timestamp}.csv";

            return response()->streamDownload(function () use ($logs): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['fecha_hora', 'accion', 'gestionado_por', 'email_gestor', 'zona', 'delegaciones', 'cambios'], ';');

                foreach ($logs as $log) {
                    $changes = collect($log->changes ?? [])
                        ->map(function (array $change, string $field): string {
                            return sprintf(
                                '%s: %s -> %s',
                                $field,
                                blank($change['from'] ?? null) ? 'Vacío' : $change['from'],
                                blank($change['to'] ?? null) ? 'Vacío' : $change['to'],
                            );
                        })
                        ->implode(' | ');

                    fputcsv($output, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        $log->action_label,
                        $log->actor_name,
                        $log->actor_email,
                        $log->target_name,
                        filled($log->target_dealerships ?? []) ? implode(' | ', $log->target_dealerships) : '',
                        $changes,
                    ], ';');
                }

                fclose($output);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        })->name('backoffice.zone-logs.export');
    });

    // The Filament page and these mutations share the same effective permission.
    Route::post('/backoffice/conservacion-excepcional', [AdminChatRetentionHoldController::class, 'store'])
        ->name('backoffice.chat-retention-holds.store');
    Route::patch('/backoffice/conservacion-excepcional/{conversation}', [AdminChatRetentionHoldController::class, 'update'])
        ->whereNumber('conversation')
        ->name('backoffice.chat-retention-holds.update');
    Route::delete('/backoffice/conservacion-excepcional/{conversation}/desactivar', [AdminChatRetentionHoldController::class, 'destroy'])
        ->whereNumber('conversation')
        ->name('backoffice.chat-retention-holds.destroy');
    Route::post('/backoffice/conservacion-excepcional/usuarios', [AdminChatRetentionHoldController::class, 'storeUser'])
        ->name('backoffice.chat-retention-holds.users.store');
    Route::patch('/backoffice/conservacion-excepcional/usuarios/{userHold}', [AdminChatRetentionHoldController::class, 'updateUser'])
        ->whereNumber('userHold')
        ->name('backoffice.chat-retention-holds.users.update');
    Route::delete('/backoffice/conservacion-excepcional/usuarios/{userHold}/desactivar', [AdminChatRetentionHoldController::class, 'destroyUser'])
        ->whereNumber('userHold')
        ->name('backoffice.chat-retention-holds.users.destroy');

    Route::post('/leaderboard/sync', SalesforceLeaderboardSyncController::class)->name('leaderboard.sync');

    Route::get('/admin/logs/acceso-conversacion/descargar', [AdminConversationAccessLogController::class, 'export'])->name('admin.conversation-access.logs.export');

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/logs/permisos/descargar', [AdminPermissionLogController::class, 'export'])->name('admin.permission-logs.export');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/logs/tablon/descargar', [AdminBulletinLogController::class, 'export'])
            ->name('admin.bulletin-logs.export');
    });
});
