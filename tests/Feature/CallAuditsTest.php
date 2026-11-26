<?php

namespace Tests\Feature;

use App\Models\AdminPermissionGrant;
use App\Models\EnreachCallAudit;
use App\Models\EnreachCallTranscription;
use App\Models\User;
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

    private function grant(User $user): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => 'call-audits.view',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
