<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Admin\SettingController;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Saran "kondisi yang belum tertulis sebagai diagnosis" bisa dinyalakan/dimatikan Admin. Bawaan: mati. */
class ConditionSuggestionToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $koordinator;

    private FtpFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->koordinator = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        $this->folder = FtpFolder::create(['path' => '/ftp/data/x', 'name' => '000001_PASIEN_SEP1', 'nama_pasien' => 'PASIEN',
            'files' => [['path' => '/ftp/data/x/SEP.pdf', 'name' => 'SEP.pdf', 'size' => 1, 'modified' => 1]], 'fingerprint' => 'f']);
    }

    /** Tahap 1 sudah selesai; tahap 2 dijalankan dengan jawaban AI yang tetap mengirim "kondisi". */
    private function synthesize(): FolderAnalysis
    {
        $a = FolderAnalysis::create(['ftp_folder_id' => $this->folder->id, 'status' => ReviewStatus::Berjalan, 'fingerprint' => 'f']);
        FolderDocument::create(['folder_analysis_id' => $a->id, 'ftp_folder_id' => $this->folder->id, 'file_index' => 0, 'name' => 'SEP.pdf',
            'file_fingerprint' => '1|1', 'doc_type' => 'sep', 'status' => ReviewStatus::Selesai, 'summary' => ['isian' => [], 'diagnosis' => ['Pneumonia']]]);
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            'content' => [['type' => 'text', 'text' => json_encode(['ringkasan' => 'Berkas lengkap.', 'temuan' => [],
                'kondisi' => [['kondisi' => 'Kemungkinan anemia', 'bukti' => 'Hb rendah', 'dokumen' => 'SEP.pdf', 'halaman' => 1]]])]],
        ])]);

        SynthesizeFolderAnalysis::dispatchSync($a->id);

        return $a->fresh();
    }

    private function sentPrompt(): string
    {
        $r = Http::recorded()->first()[0];

        return $r['system'] . "\n" . collect($r['messages'][0]['content'])->where('type', 'text')->pluck('text')->implode("\n");
    }

    public function test_bawaan_mati_instruksi_tanpa_kondisi_dan_saran_diabaikan(): void
    {
        $this->assertFalse(SettingController::conditionSuggestionsOn());

        $a = $this->synthesize();

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertStringNotContainsStringIgnoringCase('kondisi', $this->sentPrompt(), 'Instruksi dan skema tahap 2 tanpa "kondisi"');
        $this->assertStringContainsString('"temuan"', $this->sentPrompt());
        $this->assertSame([], $a->suggestions, 'Field "kondisi" dari AI diabaikan');
        $this->actingAs($this->koordinator)->get(route('folders.show', $this->folder))->assertOk()
            ->assertDontSee('Kondisi yang belum tertulis sebagai diagnosis');
    }

    public function test_menyala_kondisi_tersimpan_dan_tampil(): void
    {
        AppSetting::put(SettingController::KEY_CONDITION_SUGGESTIONS, true, null);

        $a = $this->synthesize();

        $this->assertStringContainsString('"kondisi"', $this->sentPrompt());
        $this->assertSame('Kemungkinan anemia', $a->suggestions[0]['kondisi']);
        $this->actingAs($this->koordinator)->get(route('folders.show', $this->folder))->assertOk()
            ->assertSee('Kondisi yang belum tertulis sebagai diagnosis')->assertSee('Kemungkinan anemia');
    }

    public function test_dimatikan_menyembunyikan_saran_lama(): void
    {
        AppSetting::put(SettingController::KEY_CONDITION_SUGGESTIONS, true, null);
        $a = $this->synthesize();
        $this->assertNotEmpty($a->suggestions);

        AppSetting::put(SettingController::KEY_CONDITION_SUGGESTIONS, false, null);

        $this->actingAs($this->koordinator)->get(route('folders.show', $this->folder))->assertOk()
            ->assertDontSee('Kondisi yang belum tertulis sebagai diagnosis')->assertDontSee('Kemungkinan anemia')
            ->assertSee('Berkas lengkap.');
        $this->assertNotEmpty($a->fresh()->suggestions, 'Data lama tidak dihapus, hanya disembunyikan');
    }

    public function test_hanya_admin_yang_bisa_mengubah_dan_tercatat_di_audit(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');

        $this->actingAs($this->koordinator)->put(route('admin.settings.analysis'), ['saran_kondisi' => '1'])->assertForbidden();
        $this->assertFalse(SettingController::conditionSuggestionsOn());

        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()
            ->assertSee('Tampilkan saran kondisi yang belum tertulis sebagai diagnosis')
            ->assertSee('Bila mati, AI hanya memeriksa kelengkapan dan konsistensi dokumen (administratif).');

        $this->actingAs($admin)->put(route('admin.settings.analysis'), ['saran_kondisi' => '1'])->assertSessionHas('ok');
        $this->assertTrue(SettingController::conditionSuggestionsOn());
        $this->actingAs($admin)->put(route('admin.settings.analysis'), ['saran_kondisi' => '0'])->assertSessionHas('ok');
        $this->assertFalse(SettingController::conditionSuggestionsOn());

        $log = AuditLog::where('action', 'admin.saran_kondisi')->orderBy('id')->get();
        $this->assertCount(2, $log);
        $this->assertStringContainsString('dinyalakan', $log[0]->description);
        $this->assertStringContainsString('dimatikan', $log[1]->description);
    }
}
