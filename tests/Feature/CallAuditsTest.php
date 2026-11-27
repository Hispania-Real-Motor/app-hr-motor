<?php

namespace Tests\Feature;

use App\Models\AdminPermissionGrant;
use App\Models\EnreachCallAudit;
use App\Models\EnreachCallTranscription;
use App\Models\Dealership;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CallAuditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['database.connections.enreach_ai' => config('database.connections.sqlite')]);

        Schema::connection('enreach_ai')->create('enreach_call_transcriptions', function ($table): void {
            $table->id();
            $table->string('salesforce_task_id')->nullable();
            $table->string('call_puid')->nullable();
            $table->text('transcript_text')->nullable();
            $table->json('transcript_raw')->nullable();
            $table->boolean('audited_by_ai')->default(false);
            $table->timestamps();
        });

        Schema::connection('enreach_ai')->create('enreach_call_audits', function ($table): void {
            $table->id();
            $table->foreignId('transcription_id')->unique();
            $table->json('audit_result')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->string('rules_version')->nullable();
            $table->string('evaluator_version')->nullable();
            $table->timestamps();
        });
    }

    public function test_permission_controls_normal_navigation_and_direct_list_and_detail_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $transcription = $this->createTranscription('PUID-DENIED');
        $audit = EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'score' => 0,
            'audit_result' => ['evaluation' => []],
        ]);

        $this->actingAs($user)->get(route('call-audits.index'))->assertForbidden();
        $this->actingAs($user)->get(route('call-audits.show', $audit))->assertForbidden();
        $this->assertStringNotContainsString('Auditorías IA de llamadas', view('components.layout.navbar')->render());

        $this->grant($user);

        $this->get('/backoffice')->assertForbidden();
        $this->get(route('call-audits.index'))->assertOk()->assertSee('PUID-DENIED');
        $this->get(route('call-audits.show', $audit))
            ->assertOk()
            ->assertSee('0,00/10')
            ->assertSee('Transcripción completa')
            ->assertSeeInOrder([
                'Resumen de la llamada',
                'Fortalezas',
                'Mejoras',
                'Plan de acción',
                'Transcripción completa',
                'Información técnica',
                'Criterios de evaluación',
            ]);
        $this->assertStringContainsString('Auditorías IA de llamadas', view('components.layout.navbar')->render());
    }

    public function test_delete_permission_controls_buttons_and_removes_audit_and_transcription(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);
        $transcription = $this->createTranscription('PUID-DELETE');
        $audit = EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'score' => 6,
            'audit_result' => [],
        ]);

        $this->actingAs($user)->get(route('call-audits.index'))
            ->assertOk()
            ->assertDontSee('Eliminar auditoría');
        $this->actingAs($user)->delete(route('call-audits.destroy', $audit))->assertForbidden();
        $this->assertTrue(EnreachCallAudit::query()->whereKey($audit->id)->exists());
        $this->assertTrue(EnreachCallTranscription::query()->whereKey($transcription->id)->exists());

        $this->grant($user, 'call-audits.delete');

        $this->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('Eliminar auditoría');
        $this->get(route('call-audits.show', $audit))
            ->assertOk()
            ->assertSee('Eliminar auditoría');

        $this->delete(route('call-audits.destroy', $audit))
            ->assertRedirect(route('call-audits.index'))
            ->assertSessionHas('success');
        $this->assertFalse(EnreachCallAudit::query()->whereKey($audit->id)->exists());
        $this->assertFalse(EnreachCallTranscription::query()->whereKey($transcription->id)->exists());
    }

    public function test_list_filters_audited_transcriptions_and_distinguishes_null_score(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);

        $audited = $this->createTranscription('PUID-NULL', true);
        EnreachCallAudit::query()->create([
            'transcription_id' => $audited->id,
            'score' => null,
            'audit_result' => ['evaluation' => ['criteria' => [['name' => 'Escucha', 'score' => 0, 'justification' => 'Texto <script>']]], 'coverage' => 80],
        ]);
        $this->createTranscription('PUID-NOT-AUDITED', false);

        $this->actingAs($user)->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('PUID-NULL')
            ->assertSee('Sin nota')
            ->assertDontSee('PUID-NOT-AUDITED');
    }

    public function test_resolves_commercial_name_from_salesforce_employee_id_in_list_and_detail(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $commercial = User::factory()->create([
            'name' => 'Laura Comercial',
            'salesforce_user_id' => '005-COMMERCIAL-001',
        ]);
        $this->grant($user);

        $transcription = $this->createTranscription('PUID-COMMERCIAL');
        $audit = EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'score' => 8,
            'audit_result' => [
                'attribution' => [
                    'employeeId' => $commercial->salesforce_user_id,
                    'confirmed' => false,
                ],
            ],
        ]);

        $this->actingAs($user)->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('Laura Comercial')
            ->assertSee(route('users.show', $commercial), false)
            ->assertSee($commercial->avatar_url, false)
            ->assertDontSee('005-COMMERCIAL-001');

        $this->get(route('call-audits.show', $audit))
            ->assertOk()
            ->assertSee('Laura Comercial')
            ->assertSee('005-COMMERCIAL-001')
            ->assertDontSee('Atribución confirmada')
            ->assertDontSee('Calidad de fuente');
    }

    public function test_missing_commercial_mapping_uses_fallback_name(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);

        foreach ([
            ['puid' => 'PUID-MISSING-MAPPING', 'employeeId' => '005-COMMERCIAL-MISSING'],
            ['puid' => 'PUID-MISSING', 'employeeId' => null],
        ] as $item) {
            $transcription = $this->createTranscription($item['puid']);
            EnreachCallAudit::query()->create([
                'transcription_id' => $transcription->id,
                'audit_result' => $item['employeeId'] === null
                    ? []
                    : ['attribution' => ['employeeId' => $item['employeeId']]],
            ]);
        }

        $this->actingAs($user)->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('Comercial sin identificar');
    }

    public function test_real_audit_shape_is_mapped_to_compact_criteria_components(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);
        $transcription = $this->createTranscription('PUID-REAL-SHAPE');

        $audit = EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'score' => null,
            'audit_result' => [
                'score' => [
                    'cobertura' => 35,
                    'estado' => 'PENDIENTE - EVIDENCIA INSUFICIENTE',
                    'alertas' => ['C06: evidencia insuficiente'],
                    'criterios' => [['id' => 'C01', 'nombre' => 'Profesionalidad', 'peso' => 9]],
                ],
                'evaluation' => [
                    'fortalezas' => 'Trato cordial.',
                    'planAccion' => 'Recordar la presentación completa.',
                    'criterios' => [[
                        'id' => 'C01',
                        'valoracion' => 'N/E',
                        'justificacion' => 'No hay atribución suficiente.',
                        'evidencia' => '[00:01] Texto literal.',
                        'evidencias' => [['texto' => '[00:01] Texto literal.', 'actor' => 'CLIENTE']],
                        'contextoEvidencias' => [['id' => 'CTX1', 'texto' => '[00:02] Contexto literal.', 'actor' => 'CLIENTE']],
                    ]],
                ],
                'sourceQuality' => ['Fuente completa.'],
                'attribution' => ['source' => 'OwnerId como contexto', 'confirmed' => false],
                'rulesVersion' => 'rules-v1',
                'evaluatorVersion' => 'eval-v1',
            ],
        ]);

        $this->actingAs($user)->get(route('call-audits.show', $audit))
            ->assertOk()
            ->assertSee('35')
            ->assertSee('No evaluable')
            ->assertSee('Profesionalidad')
            ->assertSee('Fuente completa.')
            ->assertSee('No')
            ->assertSee('Contexto')
            ->assertDontSee('motivoNA')
            ->assertDontSee('evidenciaIds')
            ->assertDontSee('valoracionModelo');
    }

    public function test_not_applicable_criteria_show_only_justification(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);
        $transcription = $this->createTranscription('PUID-NA');

        $audit = EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'audit_result' => [
                'evaluation' => [
                    'criterios' => [
                        [
                            'id' => 'C10',
                            'valoracion' => 'N/A',
                            'justificacion' => 'C10 no aplica a esta llamada.',
                            'evidencias' => [['texto' => 'Evidencia C10 que no debe mostrarse.']],
                            'contextoEvidencias' => [['texto' => 'Contexto C10 que no debe mostrarse.']],
                        ],
                        [
                            'id' => 'C11',
                            'valoracion' => 'N/E',
                            'justificacion' => 'C11 no es evaluable.',
                            'evidencias' => [['texto' => 'Evidencia C11 visible.']],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)->get(route('call-audits.show', $audit))
            ->assertOk()
            ->assertSee('No aplicable')
            ->assertSee('C10 no aplica a esta llamada.')
            ->assertDontSee('Evidencia C10 que no debe mostrarse.')
            ->assertDontSee('Contexto C10 que no debe mostrarse.')
            ->assertSee('Evidencia C11 visible.');
    }

    public function test_list_sorting_is_server_side_numeric_and_keeps_nulls_last(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);

        foreach ([
            ['puid' => 'PUID-ZERO', 'score' => 0, 'coverage' => 2],
            ['puid' => 'PUID-NULL', 'score' => null, 'coverage' => null],
            ['puid' => 'PUID-EIGHT', 'score' => 8, 'coverage' => 10],
        ] as $index => $item) {
            $transcription = $this->createTranscription($item['puid']);
            $audit = EnreachCallAudit::query()->create([
                'transcription_id' => $transcription->id,
                'score' => $item['score'],
                'audit_result' => ['score' => ['cobertura' => $item['coverage']]],
            ]);
            $audit->forceFill(['created_at' => now()->addSeconds($index)])->save();
        }

        $this->actingAs($user)
            ->get(route('call-audits.index', ['sort' => 'score', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['PUID-ZERO', 'PUID-EIGHT', 'PUID-NULL'])
            ->assertSee('aria-sort="ascending"', false);

        $this->get(route('call-audits.index', ['sort' => 'score', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['PUID-EIGHT', 'PUID-ZERO', 'PUID-NULL'])
            ->assertSee('aria-sort="descending"', false);

        $this->get(route('call-audits.index', ['sort' => 'coverage', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['PUID-ZERO', 'PUID-EIGHT', 'PUID-NULL']);

        $descending = $this->get(route('call-audits.index', ['sort' => 'score', 'direction' => 'desc']))
            ->assertOk();
        $this->assertStringContainsString(
            'href="' . route('call-audits.index') . '"',
            $descending->getContent(),
        );

        $this->get(route('call-audits.index'))
            ->assertOk()
            ->assertSeeInOrder(['PUID-EIGHT', 'PUID-NULL', 'PUID-ZERO']);
    }

    public function test_list_filters_by_commercial_date_and_score_status(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $commercial = User::factory()->create([
            'name' => 'Comercial Filtrado',
            'salesforce_user_id' => 'SF-FILTERED-001',
        ]);
        $otherCommercial = User::factory()->create([
            'name' => 'Otro Comercial',
            'salesforce_user_id' => 'SF-FILTERED-002',
        ]);
        $commercialWithoutTranscription = User::factory()->create([
            'name' => 'Comercial Sin Llamadas',
            'salesforce_user_id' => 'SF-FILTERED-003',
        ]);
        $this->grant($user);

        $matchingTranscription = $this->createTranscription('PUID-FILTER-MATCH');
        $matchingAudit = EnreachCallAudit::query()->create([
            'transcription_id' => $matchingTranscription->id,
            'score' => 7,
            'audit_result' => ['attribution' => ['employeeId' => $commercial->salesforce_user_id]],
        ]);
        $matchingAudit->forceFill(['created_at' => '2026-04-15 12:00:00'])->save();

        $otherTranscription = $this->createTranscription('PUID-FILTER-OTHER');
        $otherAudit = EnreachCallAudit::query()->create([
            'transcription_id' => $otherTranscription->id,
            'score' => 8,
            'audit_result' => ['attribution' => ['employeeId' => $otherCommercial->salesforce_user_id]],
        ]);
        $otherAudit->forceFill(['created_at' => '2026-04-15 12:00:00'])->save();

        $this->actingAs($user)->get(route('call-audits.index', [
            'commercial' => $commercial->salesforce_user_id,
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'puid' => 'MATCH',
            'score_filter' => 'passed',
        ]))
            ->assertOk()
            ->assertSee('PUID-FILTER-MATCH')
            ->assertDontSee('PUID-FILTER-OTHER')
            ->assertSee('Comercial Filtrado')
            ->assertDontSee('Comercial Sin Llamadas');
    }

    public function test_zone_filter_uses_current_dealership_membership_and_limits_commercial_options(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($viewer);

        $north = Zone::query()->create(['name' => 'Zona Norte']);
        $south = Zone::query()->create(['name' => 'Zona Sur']);
        $northDealership = Dealership::factory()->create(['zone_id' => $north->id]);
        $southDealership = Dealership::factory()->create(['zone_id' => $south->id]);
        $northCommercial = User::factory()->create([
            'name' => 'Comercial Norte',
            'salesforce_user_id' => 'SF-ZONE-NORTH',
            'dealership_id' => $northDealership->id,
        ]);
        $southCommercial = User::factory()->create([
            'name' => 'Comercial Sur',
            'salesforce_user_id' => 'SF-ZONE-SOUTH',
            'dealership_id' => $southDealership->id,
        ]);

        $northTranscription = $this->createTranscription('PUID-ZONE-NORTH');
        EnreachCallAudit::query()->create([
            'transcription_id' => $northTranscription->id,
            'score' => 8,
            'audit_result' => ['attribution' => ['employeeId' => $northCommercial->salesforce_user_id]],
        ]);
        $southTranscription = $this->createTranscription('PUID-ZONE-SOUTH');
        EnreachCallAudit::query()->create([
            'transcription_id' => $southTranscription->id,
            'score' => 8,
            'audit_result' => ['attribution' => ['employeeId' => $southCommercial->salesforce_user_id]],
        ]);
        $unidentifiedTranscription = $this->createTranscription('PUID-ZONE-UNIDENTIFIED');
        EnreachCallAudit::query()->create([
            'transcription_id' => $unidentifiedTranscription->id,
            'score' => 8,
            'audit_result' => [],
        ]);

        $this->actingAs($viewer)->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('PUID-ZONE-UNIDENTIFIED')
            ->assertSee('Comercial Norte')
            ->assertSee('Comercial Sur');

        $this->get(route('call-audits.index', [
            'zone_id' => $north->id,
            'commercial' => $southCommercial->salesforce_user_id,
        ]))
            ->assertOk()
            ->assertSee('PUID-ZONE-NORTH')
            ->assertDontSee('PUID-ZONE-SOUTH')
            ->assertDontSee('PUID-ZONE-UNIDENTIFIED')
            ->assertDontSee('Comercial Sur');

        $emptyZone = Zone::query()->create(['name' => 'Zona Sin Usuarios']);
        $this->get(route('call-audits.index', ['zone_id' => $emptyZone->id]))
            ->assertOk()
            ->assertSee('No hay auditorías IA disponibles.');
    }

    public function test_score_pills_use_red_for_failed_yellow_for_mid_range_and_blue_for_high_scores(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);

        foreach ([['puid' => 'PUID-FAILED', 'score' => 4], ['puid' => 'PUID-MID', 'score' => 7.5], ['puid' => 'PUID-HIGH', 'score' => 8]] as $item) {
            $transcription = $this->createTranscription($item['puid']);
            EnreachCallAudit::query()->create([
                'transcription_id' => $transcription->id,
                'score' => $item['score'],
                'audit_result' => [],
            ]);
        }

        $this->actingAs($user)->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('bg-red-100 text-red-800', false)
            ->assertSee('bg-yellow-100 text-yellow-800', false)
            ->assertSee('bg-blue-100 text-blue-800', false);
    }

    public function test_audit_filters_can_return_results_without_rendering_a_full_page(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);
        $transcription = $this->createTranscription('PUID-AJAX');
        EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'score' => 8,
            'audit_result' => [],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('call-audits.index', ['puid' => 'AJAX', 'ajax' => 1]))
            ->assertOk()
            ->assertJsonStructure(['html', 'total'])
            ->assertJsonPath('total', 1);

        $this->assertStringContainsString('PUID-AJAX', (string) $response->json('html'));
    }

    public function test_list_highlights_listening_required_without_inferencing_it_from_score(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);

        $rootTrue = $this->createTranscription('PUID-LISTEN-NULL');
        $rootTrueAudit = EnreachCallAudit::query()->create([
            'transcription_id' => $rootTrue->id,
            'score' => null,
            'audit_result' => [
                'requiresListening' => true,
                'alerts' => [[
                    'codigo' => 'C10-CRIT',
                    'criterio' => 'C10',
                    'severidad' => 'alta',
                    'motivo' => 'Contrastar la atribución con el audio.',
                    'evidencias' => [['texto' => 'Cita literal', 'desde' => '00:12', 'hasta' => '00:15']],
                ]],
                'score' => [
                    'requiereEscucha' => false,
                    'avisos' => [[
                        'codigo' => 'C10-CRIT',
                        'criterio' => 'C10',
                        'severidad' => 'alta',
                        'motivo' => 'Contrastar la atribución con el audio.',
                        'evidencias' => [['texto' => 'Cita literal', 'desde' => '00:12', 'hasta' => '00:15']],
                    ]],
                ],
            ],
        ]);

        $rootFalse = $this->createTranscription('PUID-LISTEN-FALSE');
        $rootFalseAudit = EnreachCallAudit::query()->create([
            'transcription_id' => $rootFalse->id,
            'score' => 0,
            'audit_result' => ['requiresListening' => 'false', 'score' => ['requiereEscucha' => true]],
        ]);

        $fallback = $this->createTranscription('PUID-LISTEN-FALLBACK');
        $fallbackAudit = EnreachCallAudit::query()->create([
            'transcription_id' => $fallback->id,
            'score' => 0,
            'audit_result' => ['score' => ['requiereEscucha' => true]],
        ]);

        $missing = $this->createTranscription('PUID-LISTEN-MISSING');
        $missingAudit = EnreachCallAudit::query()->create([
            'transcription_id' => $missing->id,
            'score' => 0,
            'audit_result' => [],
        ]);

        $this->assertTrue($rootTrueAudit->requiresListening());
        $this->assertCount(1, $rootTrueAudit->listeningAlerts());
        $this->assertFalse($rootFalseAudit->requiresListening());
        $this->assertTrue($fallbackAudit->requiresListening());
        $this->assertFalse($missingAudit->requiresListening());

        $this->actingAs($user)->get(route('call-audits.index'))
            ->assertOk()
            ->assertSee('PUID-LISTEN-NULL')
            ->assertSee('PUID-LISTEN-FALLBACK')
            ->assertSee('Requiere escucha')
            ->assertSee('bg-red-50/80', false);

        $this->get(route('call-audits.show', $rootTrueAudit))
            ->assertOk()
            ->assertSee('Requiere escucha del responsable')
            ->assertSee('C10-CRIT')
            ->assertSee('Cita literal')
            ->assertSee('00:12')
            ->assertSee('Sin nota');

        $this->get(route('call-audits.show', $rootFalseAudit))
            ->assertOk()
            ->assertDontSee('Requiere escucha del responsable');

        $this->get(route('call-audits.index', ['requires_listening' => 1]))
            ->assertOk()
            ->assertSee('PUID-LISTEN-NULL')
            ->assertSee('PUID-LISTEN-FALLBACK')
            ->assertDontSee('PUID-LISTEN-FALSE')
            ->assertDontSee('PUID-LISTEN-MISSING');
    }

    public function test_detail_hides_only_alerts_already_shown_in_listening_block(): void
    {
        $audit = new EnreachCallAudit;
        $audit->audit_result = [
            'alerts' => [[
                'codigo' => 'C10',
                'criterio' => 'C10',
                'motivo' => 'Contrastar la frase con el audio.',
                'evidencias' => [['texto' => 'Cita repetida', 'desde' => '00:10']],
            ]],
            'score' => [
                'avisos' => [[
                    'codigo' => 'C10',
                    'criterio' => 'C10',
                    'motivo' => 'Contrastar la frase con el audio.',
                    'evidencias' => [['texto' => 'Cita repetida', 'desde' => '00:10']],
                ], [
                    'codigo' => 'C10',
                    'criterio' => 'C10',
                    'motivo' => 'Hecho distinto con el mismo código.',
                    'evidencias' => [['texto' => 'Otra cita', 'desde' => '00:20']],
                ]],
            ],
        ];

        $this->assertCount(2, $audit->listeningAlerts());
        $this->assertCount(0, $audit->additionalAlerts());

        $audit->audit_result['score']['alertas'] = [
            'Contrastar la frase con el audio.',
            'Aviso adicional distinto.',
        ];

        $additional = $audit->additionalAlerts();

        $this->assertCount(1, $additional);
        $this->assertSame('Aviso adicional distinto.', $additional[0]['reason']);
    }

    public function test_detail_hides_legacy_critical_text_when_it_matches_structured_incident(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'extra_role' => null]);
        $this->grant($user);
        $transcription = $this->createTranscription('PUID-MP01-DUPLICATE');
        $audit = EnreachCallAudit::query()->create([
            'transcription_id' => $transcription->id,
            'audit_result' => [
                'requiresListening' => true,
                'alerts' => [[
                    'codigo' => 'MP01',
                    'criterio' => 'C03',
                    'motivo' => "El comercial utiliza una expresión ofensiva ('cabrón') hacia el cliente.",
                    'evidencias' => [['texto' => "No me llame así, cabrón.", 'desde' => '00:42']],
                ]],
                'score' => [
                    'alertas' => ["CRÍTICO: MP01 El comercial utiliza una expresión ofensiva ('cabrón') hacia el cliente."],
                ],
            ],
        ]);

        $html = (string) $this->actingAs($user)->get(route('call-audits.show', $audit))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, "El comercial utiliza una expresión ofensiva ('cabrón') hacia el cliente."));
        $this->assertStringContainsString('Código: MP01', $html);
        $this->assertStringNotContainsString('Alertas relevantes', $html);
    }

    public function test_structured_evidence_replaces_combined_text_and_deduplicates_per_criterion(): void
    {
        $audit = new EnreachCallAudit;
        $audit->audit_result = [
            'score' => [
                'criterios' => [
                    ['id' => 'C03', 'nombre' => 'Criterio tres', 'peso' => 1],
                    ['id' => 'C04', 'nombre' => 'Criterio cuatro', 'peso' => 1],
                ],
            ],
            'evaluation' => [
                'criterios' => [
                    [
                        'id' => 'C03',
                        'valoracion' => 'Cumple',
                        'evidencia' => 'BLOQUE COMBINADO QUE NO DEBE MOSTRARSE',
                        'evidencias' => [
                            ['id' => 'F03', 'texto' => 'Cita C03 uno', 'actor' => 'CLIENTE', 'desde' => '00:01'],
                            ['id' => 'F03', 'texto' => 'Duplicada por id', 'actor' => 'CLIENTE', 'desde' => '00:01'],
                            ['texto' => 'Cita C03 dos', 'actor' => 'CLIENTE', 'desde' => '00:02'],
                            ['texto' => 'Cita C03 dos', 'actor' => 'CLIENTE', 'desde' => '00:03'],
                        ],
                        'contextoEvidencias' => [['texto' => 'Contexto C03']],
                    ],
                    [
                        'id' => 'C04',
                        'valoracion' => 'Cumple',
                        'evidencia' => 'OTRO BLOQUE COMBINADO',
                        'evidencias' => [
                            ['id' => 'F04', 'texto' => 'Cita C04 uno', 'actor' => 'COMERCIAL', 'desde' => '00:04'],
                            ['id' => 'F05', 'texto' => 'Cita C04 dos', 'actor' => 'CLIENTE', 'desde' => '00:05'],
                        ],
                    ],
                ],
            ],
        ];

        $criteria = collect($audit->criteriaForDisplay())->keyBy('id');

        $this->assertCount(3, $criteria['C03']['evidence']);
        $this->assertSame(['Cita C03 uno', 'Cita C03 dos', 'Cita C03 dos'], collect($criteria['C03']['evidence'])->pluck('text')->all());
        $this->assertSame(['Contexto C03'], collect($criteria['C03']['context'])->pluck('text')->all());
        $this->assertCount(2, $criteria['C04']['evidence']);
        $this->assertNotContains('BLOQUE COMBINADO QUE NO DEBE MOSTRARSE', collect($criteria['C03']['evidence'])->pluck('text')->all());
        $this->assertNotContains('OTRO BLOQUE COMBINADO', collect($criteria['C04']['evidence'])->pluck('text')->all());
    }

    private function createTranscription(string $puid, bool $audited = true): EnreachCallTranscription
    {
        return EnreachCallTranscription::query()->create([
            'salesforce_task_id' => 'TASK-' . $puid,
            'call_puid' => $puid,
            'transcript_text' => 'Contenido no confiable',
            'audited_by_ai' => $audited,
        ]);
    }

    private function grant(User $user, string $permission = 'call-audits.view'): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permission,
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
