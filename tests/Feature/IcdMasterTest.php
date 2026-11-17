<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Admin\SettingController;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\CodeSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\IcdCode;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IcdMasterTest extends TestCase
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

    private function impor(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'icd');
        file_put_contents($f, "system,code,title,valid,accpdx,asterisk,valid_idrg\n"
            ."icd10,J18.9,\"Pneumonia, unspecified (master)\",Y,Y,N,Y\n"
            ."icd10,J18,Pneumonia organism unspecified,N,Y,N,N\n"
            ."icd10,G01,Meningitis in bacterial diseases classified elsewhere,Y,N,Y,Y\n"
            ."icd9cm,0.450,Insertion of drug-eluting stent,Y,Y,N,Y\n");
        $this->artisan('casemix:icd-import', ['file' => $f])->expectsOutputToContain('Diimpor: 4 kode')->assertSuccessful();
        unlink($f);
    }

    private function kode(array $over): array
    {
        return $over + ['system' => 'icd10', 'peran' => 'utama', 'title' => 'judul AI', 'alasan' => 'x', 'sumber' => 'resume',
            'bukti' => 'Dx', 'dokumen' => 'RESUME.pdf', 'halaman' => 1, 'keyakinan' => 'kuat', 'tercantum_di_resume' => true];
    }

    public function test_impor_dan_lookup(): void
    {
        $this->impor();
        $this->impor(); // upsert, tidak dobel
        $this->assertSame(4, IcdCode::count());
        $this->assertSame('Insertion of drug-eluting stent', IcdCode::lookup('icd9cm', ' 0.450 ')->title);
        $this->assertSame('0.450', IcdCode::normalize(' 0.450 '));
        $this->assertSame('J18.9', IcdCode::lookup('icd10', 'j18.9')->code);
        $this->assertFalse(IcdCode::lookup('icd10', 'J18')->valid);
        $this->assertNull(IcdCode::lookup('icd10', 'Z99.9'));
        $this->assertTrue(CodeSuggestion::validFormat('icd9cm', '0.450'));
        $this->assertTrue(CodeSuggestion::validFormat('icd9cm', '5'));
    }

    public function test_lencana_tanpa_membuang_saran(): void
    {
        $this->impor();
        AppSetting::put(SettingController::KEY_CODE_SUGGESTIONS, true, null);
        $a = FolderAnalysis::create(['ftp_folder_id' => $this->folder->id, 'status' => ReviewStatus::Berjalan, 'fingerprint' => 'f']);
        FolderDocument::create(['folder_analysis_id' => $a->id, 'ftp_folder_id' => $this->folder->id, 'file_index' => 0, 'name' => 'RESUME.pdf',
            'file_fingerprint' => '1|1', 'doc_type' => 'resume_medis', 'status' => ReviewStatus::Selesai, 'summary' => ['isian' => [], 'diagnosis' => []]]);
        $kode = [
            $this->kode(['code' => 'J18.9']),
            $this->kode(['code' => 'G01', 'peran' => 'utama']),
            $this->kode(['code' => 'J18', 'peran' => 'sekunder']),
            $this->kode(['code' => 'Z99.9', 'peran' => 'sekunder']),
            $this->kode(['system' => 'icd9cm', 'code' => '0.450', 'peran' => 'tindakan']),
        ];
        Http::fake(['*/v1/messages' => Http::response([
            'id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            'content' => [['type' => 'text', 'text' => json_encode(['ringkasan' => 'ok', 'rubrik' => [], 'kode' => $kode])]],
        ])]);
        SynthesizeFolderAnalysis::dispatchSync($a->id);

        $this->assertSame(5, CodeSuggestion::count());
        $this->assertSame('Pneumonia, unspecified (master)', CodeSuggestion::where('code', 'J18.9')->value('title'));
        $this->assertSame('judul AI', CodeSuggestion::where('code', 'Z99.9')->value('title'));
        $this->assertSame([], CodeSuggestion::where('code', 'J18.9')->first()->lencana());

        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->actingAs($koor)->get(route('folders.show', $this->folder))->assertOk()
            ->assertSee('tidak ditemukan di master')->assertSee('kode kategori, perlu digit lengkap')
            ->assertSee('tidak boleh jadi diagnosis utama')->assertSee('kode asterisk, perlu pasangan dagger')
            ->assertSee('Insertion of drug-eluting stent');
    }
}
