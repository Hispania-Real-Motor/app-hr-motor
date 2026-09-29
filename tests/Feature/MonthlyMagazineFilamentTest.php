<?php

namespace Tests\Feature;

use App\Filament\Pages\MonthlyMagazinePage;
use App\Filament\Pages\MonthlyMagazineLogsPage;
use App\Models\MonthlyMagazineActivityLog;
use App\Models\MonthlyMagazineSetting;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use App\Services\MonthlyMagazineActivityLogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyMagazineFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected array $createdMagazineFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdMagazineFiles as $path) {
            $absolutePath = public_path($path);

            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }
        }

        parent::tearDown();
    }

    public function test_admin_and_manager_can_open_the_filament_magazine_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'gestor@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'group_role' => User::ROLE_MANAGER,
            'permission_key' => 'magazine.manage',
            'granted_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(MonthlyMagazinePage::getUrl())
            ->assertOk()
            ->assertSee('Revista')
            ->assertSee('Ver logs', false);

        $this->actingAs($manager)
            ->get(MonthlyMagazinePage::getUrl())
            ->assertOk()
            ->assertSee('Revista')
            ->assertDontSee('Ver logs', false);
    }

    public function test_admin_can_open_the_filament_magazine_logs_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        MonthlyMagazineActivityLog::query()->create([
            'action' => MonthlyMagazineActivityLog::ACTION_UPDATED,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'target_name' => 'Revista Agosto',
            'target_reference' => 'revista/revista-agosto-2026.pdf',
            'changes' => [
                'tag_label' => ['from' => 'Julio', 'to' => 'Agosto'],
            ],
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(MonthlyMagazineLogsPage::getUrl())
            ->assertOk()
            ->assertSee('Logs de revista')
            ->assertSee('tag_label: Julio -> Agosto')
            ->assertSee('Admin Principal')
            ->assertDontSee('Sin cambios adicionales registrados');
    }

    public function test_empty_magazine_log_rows_are_removed_when_opening_the_logs_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        $emptyLog = MonthlyMagazineActivityLog::query()->create([
            'action' => MonthlyMagazineActivityLog::ACTION_UPDATED,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'target_name' => 'Revista Junio',
            'target_reference' => 'revista/revista-junio-2028.pdf',
            'changes' => [],
            'created_at' => now(),
        ]);

        $validLog = MonthlyMagazineActivityLog::query()->create([
            'action' => MonthlyMagazineActivityLog::ACTION_UPDATED,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'target_name' => 'Revista Agosto',
            'target_reference' => 'revista/revista-agosto-2026.pdf',
            'changes' => [
                'tag_label' => ['from' => 'Julio', 'to' => 'Agosto'],
            ],
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(MonthlyMagazineLogsPage::getUrl())
            ->assertOk()
            ->assertSee('tag_label: Julio -> Agosto')
            ->assertDontSee('Revista Junio')
            ->assertDontSee('Sin cambios adicionales registrados');

        $this->assertDatabaseMissing('monthly_magazine_activity_logs', [
            'id' => $emptyLog->id,
        ]);
        $this->assertDatabaseHas('monthly_magazine_activity_logs', [
            'id' => $validLog->id,
        ]);
    }

    public function test_filament_magazine_page_replaces_the_single_magazine_and_logs_it(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        $oldPdfPath = 'revista/revista-abril-2026.pdf';
        File::ensureDirectoryExists(public_path('revista'));
        File::put(public_path($oldPdfPath), '%PDF-1.4 old magazine');
        $this->createdMagazineFiles[] = $oldPdfPath;

        MonthlyMagazineSetting::query()->create([
            'tag_label' => 'Abril',
            'pdf_path' => $oldPdfPath,
            'original_filename' => 'revista-abril.pdf',
            'updated_by_user_id' => $admin->id,
        ]);

        Livewire::actingAs($admin);

        Livewire::test(MonthlyMagazinePage::class)
            ->set('data.tag_label', 'Mayo')
            ->set('data.file_name', 'revista mayo 2026')
            ->set('data.magazine_file', UploadedFile::fake()->create('revista-mayo.pdf', 1024, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('monthly_magazine_settings', 1);

        $magazine = MonthlyMagazineSetting::query()->first();

        $this->assertNotNull($magazine);
        $this->assertSame('Mayo', $magazine->tag_label);
        $this->assertSame('revista/revista-mayo-2026.pdf', $magazine->pdf_path);
        $this->assertFileExists(public_path($magazine->pdf_path));
        $this->assertFileExists(public_path($oldPdfPath));

        $this->createdMagazineFiles[] = $magazine->pdf_path;

        $log = MonthlyMagazineActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame(MonthlyMagazineActivityLog::ACTION_UPDATED, $log->action);
        $this->assertSame('Mayo', $log->target_name);
        $this->assertSame('revista/revista-mayo-2026.pdf', $log->target_reference);
        $this->assertSame([
            'tag_label' => ['from' => 'Abril', 'to' => 'Mayo'],
            'pdf_path' => ['from' => 'revista/revista-abril-2026.pdf', 'to' => 'revista/revista-mayo-2026.pdf'],
            'original_filename' => ['from' => 'revista-abril.pdf', 'to' => 'revista-mayo.pdf'],
        ], $log->changes);
    }

    public function test_filament_magazine_logs_only_changed_fields_on_update(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        $oldPdfPath = 'revista/revista-junio-2027.pdf';
        File::ensureDirectoryExists(public_path('revista'));
        File::put(public_path($oldPdfPath), '%PDF-1.4 old magazine');
        $this->createdMagazineFiles[] = $oldPdfPath;

        MonthlyMagazineSetting::query()->create([
            'tag_label' => 'Junio',
            'pdf_path' => $oldPdfPath,
            'original_filename' => 'Invoice-LZMRHQ5B-0001.pdf',
            'updated_by_user_id' => $admin->id,
        ]);

        Livewire::actingAs($admin);

        Livewire::test(MonthlyMagazinePage::class)
            ->set('data.tag_label', 'Junio')
            ->set('data.file_name', 'revista junio 2028')
            ->set('data.magazine_file', UploadedFile::fake()->create('Invoice-LZMRHQ5B-0001.pdf', 1024, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $log = MonthlyMagazineActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame([
            'pdf_path' => ['from' => 'revista/revista-junio-2027.pdf', 'to' => 'revista/revista-junio-2028.pdf'],
        ], $log->changes);
    }

    public function test_magazine_log_writer_skips_empty_changes(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        app(MonthlyMagazineActivityLogWriter::class)->record(
            actor: $admin,
            action: MonthlyMagazineActivityLog::ACTION_UPDATED,
            targetName: 'Revista Junio',
            targetReference: 'revista/revista-junio-2028.pdf',
            changes: [
                'tag_label' => ['from' => 'Junio', 'to' => 'Junio'],
                'pdf_path' => ['from' => 'revista/revista-junio-2027.pdf', 'to' => 'revista/revista-junio-2027.pdf'],
                'original_filename' => ['from' => 'Invoice-LZMRHQ5B-0001.pdf', 'to' => 'Invoice-LZMRHQ5B-0001.pdf'],
            ],
        );

        $this->assertDatabaseCount('monthly_magazine_activity_logs', 0);
    }

    public function test_historical_magazine_logs_can_be_normalized_retroactively(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        $log = MonthlyMagazineActivityLog::query()->create([
            'action' => MonthlyMagazineActivityLog::ACTION_UPDATED,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'target_name' => 'Revista Junio',
            'target_reference' => 'revista/revista-junio-2028.pdf',
            'changes' => [
                'tag_label' => ['from' => 'Junio', 'to' => 'Junio'],
                'pdf_path' => ['from' => 'revista/revista-junio-2027.pdf', 'to' => 'revista/revista-junio-2028.pdf'],
                'original_filename' => ['from' => 'Invoice-LZMRHQ5B-0001.pdf', 'to' => 'Invoice-LZMRHQ5B-0001.pdf'],
            ],
            'created_at' => now(),
        ]);

        $updated = app(MonthlyMagazineActivityLogWriter::class)->cleanupHistoricalRecords();

        $this->assertSame(1, $updated);

        $log->refresh();

        $this->assertSame([
            'pdf_path' => ['from' => 'revista/revista-junio-2027.pdf', 'to' => 'revista/revista-junio-2028.pdf'],
        ], $log->changes);
    }

    public function test_historical_magazine_logs_without_real_changes_are_deleted_retroactively(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        $log = MonthlyMagazineActivityLog::query()->create([
            'action' => MonthlyMagazineActivityLog::ACTION_UPDATED,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'target_name' => 'Revista Junio',
            'target_reference' => 'revista/revista-junio-2028.pdf',
            'changes' => [
                'tag_label' => ['from' => 'Junio', 'to' => 'Junio'],
                'pdf_path' => ['from' => 'revista/revista-junio-2027.pdf', 'to' => 'revista/revista-junio-2027.pdf'],
                'original_filename' => ['from' => 'Invoice-LZMRHQ5B-0001.pdf', 'to' => 'Invoice-LZMRHQ5B-0001.pdf'],
            ],
            'created_at' => now(),
        ]);

        $processed = app(MonthlyMagazineActivityLogWriter::class)->cleanupHistoricalRecords();

        $this->assertSame(1, $processed);
        $this->assertDatabaseMissing('monthly_magazine_activity_logs', [
            'id' => $log->id,
        ]);
    }

    public function test_filament_magazine_page_records_creation_on_first_upload(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Admin Principal',
            'email' => 'admin@example.com',
        ]);

        Livewire::actingAs($admin);

        Livewire::test(MonthlyMagazinePage::class)
            ->set('data.tag_label', 'Junio')
            ->set('data.file_name', 'revista junio 2026')
            ->set('data.magazine_file', UploadedFile::fake()->create('revista-junio.pdf', 1024, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors();

        $log = MonthlyMagazineActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame(MonthlyMagazineActivityLog::ACTION_CREATED, $log->action);
        $this->assertSame('Junio', $log->target_name);
        $this->assertSame('revista/revista-junio-2026.pdf', $log->target_reference);
        $this->assertSame([
            'tag_label' => ['from' => 'Abril', 'to' => 'Junio'],
            'pdf_path' => ['from' => 'revista/revista-abril-2026.pdf', 'to' => 'revista/revista-junio-2026.pdf'],
            'original_filename' => ['from' => null, 'to' => 'revista-junio.pdf'],
        ], $log->changes);
    }

    public function test_filament_magazine_page_deletes_the_current_magazine_and_logs_it(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        $pdfPath = 'revista/revista-julio-2026.pdf';
        File::ensureDirectoryExists(public_path('revista'));
        File::put(public_path($pdfPath), '%PDF-1.4 magazine');
        $this->createdMagazineFiles[] = $pdfPath;

        MonthlyMagazineSetting::query()->create([
            'tag_label' => 'Julio',
            'pdf_path' => $pdfPath,
            'original_filename' => 'revista-julio.pdf',
            'updated_by_user_id' => $admin->id,
        ]);

        Livewire::actingAs($admin);

        Livewire::test(MonthlyMagazinePage::class)
            ->call('delete');

        $this->assertDatabaseCount('monthly_magazine_settings', 0);
        $this->assertFileDoesNotExist(public_path($pdfPath));

        $log = MonthlyMagazineActivityLog::query()->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame(MonthlyMagazineActivityLog::ACTION_DELETED, $log->action);
        $this->assertSame('Julio', $log->target_name);
        $this->assertSame($pdfPath, $log->target_reference);
        $this->assertSame([
            'tag_label' => ['from' => 'Julio', 'to' => null],
            'pdf_path' => ['from' => $pdfPath, 'to' => null],
            'original_filename' => ['from' => 'revista-julio.pdf', 'to' => null],
        ], $log->changes);
    }

    public function test_manager_cannot_open_the_filament_magazine_logs_page(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'gestor@example.com',
        ]);

        $this->actingAs($manager)
            ->get(MonthlyMagazineLogsPage::getUrl())
            ->assertForbidden();
    }
}
