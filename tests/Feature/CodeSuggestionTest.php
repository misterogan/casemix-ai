<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Admin\SettingController;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\CodeSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Saran kode ICD-10/ICD-9-CM 2010 dari AI: bawaan mati, wajib bukti + halaman, format dicek. */
class CodeSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private FtpFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        $this->folder = FtpFolder::create(['path' => '/ftp/data/x', 'name' => '000001_PASIEN_SEP1', 'nama_pasien' => 'PASIEN',
            'files' => [['path' => '/ftp/data/x/RESUME.pdf', 'name' => 'RESUME.pdf', 'size' => 1, 'modified' => 1]], 'fingerprint' => 'f']);
    }

    private function kode(array $over = []): array
    {
        return $over + ['system' => 'icd10', 'peran' => 'utama', 'code' => 'J18.9', 'title' => 'Pneumonia, unspecified', 'alasan' => 'Diagnosis utama resume',
            'sumber' => 'resume', 'bukti' => 'Dx: Pneumonia', 'dokumen' => 'RESUME.pdf', 'halaman' => 1, 'keyakinan' => 'kuat', 'tercantum_di_resume' => true];
    }

    private function synthesize(array $kode): FolderAnalysis
    {
        $a = FolderAnalysis::create(['ftp_folder_id' => $this->folder->id, 'status' => ReviewStatus::Berjalan, 'fingerprint' => 'f']);
        FolderDocument::create(['folder_analysis_id' => $a->id, 'ftp_folder_id' => $this->folder->id, 'file_index' => 0, 'name' => 'RESUME.pdf',
            'file_fingerprint' => '1|1', 'doc_type' => 'resume_medis', 'status' => ReviewStatus::Selesai, 'summary' => ['isian' => [], 'diagnosis' => ['Pneumonia']]]);
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            'content' => [['type' => 'text', 'text' => json_encode(['ringkasan' => 'Berkas lengkap.', 'rubrik' => [], 'kode' => $kode])]],
        ])]);

        SynthesizeFolderAnalysis::dispatchSync($a->id);

        return $a->fresh();
    }

    public function test_bawaan_mati_tidak_ada_saran_kode(): void
    {
        $this->assertFalse(SettingController::codeSuggestionsOn());

        $a = $this->synthesize([$this->kode()]);

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertSame(0, CodeSuggestion::count());
        foreach (Http::recorded() as [$r]) {
            $this->assertStringNotContainsString('"kode":[', json_encode($r->data()));
        }
    }

    public function test_format_benar_tersimpan_tanpa_dibersihkan_icdguard(): void
    {
        AppSetting::put(SettingController::KEY_CODE_SUGGESTIONS, true, null);

        $a = $this->synthesize([$this->kode(), $this->kode(['system' => 'icd9cm', 'peran' => 'tindakan', 'code' => '99.04', 'title' => 'Transfusion of packed cells', 'sumber' => 'cppt', 'halaman' => 2])]);

        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertSame(['J18.9', '99.04'], CodeSuggestion::orderBy('id')->pluck('code')->all());
        $c = CodeSuggestion::first();
        $this->assertSame('Pneumonia, unspecified', $c->title);
        $this->assertSame($a->documents()->first()->id, $c->folder_document_id);
        $this->assertTrue($c->tercantum_di_resume);
        $this->assertNull($c->status_tindak_lanjut);
    }

    public function test_format_salah_dibuang_dan_dicatat(): void
    {
        AppSetting::put(SettingController::KEY_CODE_SUGGESTIONS, true, null);
        Log::spy();

        $this->synthesize([
            $this->kode(['code' => 'J189']),
            $this->kode(['code' => '18.9']),
            $this->kode(['system' => 'icd9cm', 'peran' => 'tindakan', 'code' => '9904']),
            $this->kode(['system' => 'icd9cm', 'peran' => 'utama', 'code' => '99.04']),
            $this->kode(['code' => 'E11.9']),
        ]);

        $this->assertSame(['E11.9'], CodeSuggestion::pluck('code')->all());
        Log::shouldHaveReceived('warning')->times(4);
    }

    public function test_tanpa_bukti_atau_halaman_dibuang(): void
    {
        AppSetting::put(SettingController::KEY_CODE_SUGGESTIONS, true, null);

        $this->synthesize([$this->kode(['bukti' => '']), $this->kode(['halaman' => null]), $this->kode(['code' => 'I10'])]);

        $this->assertSame(['I10'], CodeSuggestion::pluck('code')->all());
    }

    public function test_toggle_admin_diaudit(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertSee('saran kode ICD-10');
        $this->actingAs($admin)->put(route('admin.settings.analysis'), ['saran_kode' => '1'])->assertSessionHas('ok');
        $this->assertTrue(SettingController::codeSuggestionsOn());
        $this->assertSame(1, AuditLog::where('action', 'admin.saran_kode')->count());
    }
}
