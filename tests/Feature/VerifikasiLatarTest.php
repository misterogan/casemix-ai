<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\VerifikasiBerkas;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\AppSetting;
use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderSync;
use App\Services\Verification\VerifikasiLatar;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__ . '/FolderVerificationTest.php'; // FakeFtp

class VerifikasiLatarTest extends TestCase
{
    use RefreshDatabase;

    private FakeFtp $ftp;

    private User $koordinator;

    /** Jumlah job VerifikasiBerkas (Queue::fake) yang sudah dijalankan manual. */
    private int $dijalankan = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('casemix.storage_disk'));
        $this->seed(RolePermissionSeeder::class);
        $this->koordinator = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->ftp = new FakeFtp;
        $this->app->instance(SimrsFtp::class, $this->ftp);
        AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji']);
        Queue::fake();
    }

    /** @return FtpFolder[] */
    private function berkas(int $n, array $files = ['A.png', 'B.png', 'C.png']): array
    {
        $this->ftp->folders = collect(range(1, $n))->map(fn ($i) => [
            'path' => "/ftp/data/f$i", 'folder' => "f$i", 'no_rm' => sprintf('%06d', $i), 'nama' => "PASIEN $i", 'no_sep' => "SEP$i",
            'jenis_rawat' => 'inap', 'dikenali' => true,
            'files' => array_map(fn ($f) => ['path' => "/ftp/data/f$i/$f", 'name' => $f, 'size' => 1000, 'modified' => 1_794_000_000], $files),
        ])->all();
        app(FolderSync::class)->run();

        return FtpFolder::orderBy('id')->get()->all();
    }

    private function fakeAi(int $n): void
    {
        $seq = Http::sequence();
        foreach (range(1, $n) as $_) {
            $seq->push(['id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
                'content' => [['type' => 'text', 'text' => json_encode(['isian' => [], 'temuan' => [], 'ringkasan' => 'ok', 'kondisi' => []])]]]);
        }
        Http::fake(['*/v1/messages' => $seq]);
    }

    /** Jalankan job VerifikasiBerkas berikutnya yang didorong ke antrean (tiruan). False bila tidak ada lagi. */
    private function langkah(): bool
    {
        $jobs = Queue::pushed(VerifikasiBerkas::class)->values();
        if ($this->dijalankan >= $jobs->count()) {
            return false;
        }
        app()->call([$jobs[$this->dijalankan++], 'handle']);

        return true;
    }

    private function mulai(FtpFolder $f): void
    {
        $a = app(VerifikasiLatar::class)->mulai($f, $this->koordinator->id);
        app()->call([new RunFolderAnalysis($a->id), 'handle']);
    }

    public function test_jeda_di_tengah_lalu_lanjut_mulai_dari_file_berikutnya(): void
    {
        [$f] = $this->berkas(1);
        $this->fakeAi(4); // 3 file + periksa silang
        $this->mulai($f);
        $this->assertSame('antre', $f->fresh()->verifikasi_status);

        $this->langkah(); // file pertama
        $f->refresh();
        $this->assertSame('berjalan', $f->verifikasi_status);
        $this->assertSame([1, 3], [$f->verifikasi_file_selesai, $f->verifikasi_file_total]);
        $this->assertCount(1, Http::recorded());

        // Jeda saat berjalan -> menjeda; job berikutnya berhenti rapi tanpa memanggil AI
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.jeda', $f))->assertSessionHas('ok');
        $this->assertSame('menjeda', $f->fresh()->verifikasi_status);
        $this->langkah();
        $f->refresh();
        $this->assertSame('dijeda', $f->verifikasi_status);
        $this->assertSame(ReviewStatus::Dijeda, $f->analyses()->first()->status);
        $this->assertCount(1, Http::recorded());
        $this->assertFalse($this->langkah(), 'Tidak ada job baru setelah dijeda');
        $this->actingAs($this->koordinator)->get(route('folders.index'))->assertSee('Dijeda')->assertSee('1/3 file')->assertSee('Lanjutkan');

        // Lanjutkan: mulai dari file berikutnya; file pertama tidak dibaca ulang
        $selesai = $f->analyses()->first()->documents()->where('status', 'selesai')->pluck('id');
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.lanjut', $f))->assertSessionHas('ok');
        while ($this->langkah());
        $f->refresh();
        $a = $f->analyses()->first();
        $this->assertSame(ReviewStatus::Selesai, $a->status, (string) $a->error);
        $this->assertSame('selesai', $f->verifikasi_status);
        $this->assertSame([3, 3], [$f->verifikasi_file_selesai, $f->verifikasi_file_total]);
        $this->assertCount(4, Http::recorded(), '2 file sisa + periksa silang; file yang selesai tidak dianalisa ulang');
        $this->assertEquals($selesai->all(), $a->documents()->whereKey($selesai)->where('status', 'selesai')->pluck('id')->all());
    }

    public function test_tidak_ada_dua_job_untuk_berkas_yang_sama(): void
    {
        [$f] = $this->berkas(1);
        $this->mulai($f);
        $this->assertSame(1, Queue::pushed(VerifikasiBerkas::class)->count());
        $lama = $f->fresh()->verifikasi_token;

        // Masih antre: lanjut & massal ditolak / dilewati
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.lanjut', $f))->assertSessionHas('warn', 'Berkas ini sedang diverifikasi.');
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.massal'), ['ids' => [$f->id]])->assertSessionHas('ok');
        $this->assertSame(1, Queue::pushed(VerifikasiBerkas::class)->count());
        Queue::assertPushed(RunFolderAnalysis::class, 1);

        // Jeda lalu lanjut dua kali: hanya satu job baru
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.jeda', $f));
        $this->assertSame('dijeda', $f->fresh()->verifikasi_status);
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.lanjut', $f))->assertSessionHas('ok');
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.lanjut', $f))->assertSessionHas('warn');
        $this->assertSame(2, Queue::pushed(VerifikasiBerkas::class)->count());

        // Job dengan token lama berhenti tanpa memanggil AI
        Http::fake();
        app()->call([new VerifikasiBerkas($f->id, $lama), 'handle']);
        Http::assertNothingSent();
        $this->assertSame('antre', $f->fresh()->verifikasi_status);
    }

    public function test_massal_batas_lewati_selesai_dan_anggaran(): void
    {
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.massal'), ['ids' => range(1, 51)])->assertSessionHasErrors('ids');

        [$a, $b, $c] = $this->berkas(3);
        $c->update(['analysis_status' => 'selesai']);

        $this->actingAs($this->koordinator)->postJson(route('verifikasi.latar.perkiraan'), ['ids' => [$a->id, $b->id, $c->id]])
            ->assertOk()->assertJson(['diproses' => 2, 'dilewati' => 1, 'boleh' => true]);

        // Anggaran: rata-rata US$0,08/berkas, sisa US$0,02 -> ditolak, tidak ada job
        AppSetting::put(AiBudget::KEY_BUDGET, '0.10');
        AiUsage::create(['jenis' => 'analisa', 'ref_id' => 999, 'model' => 'claude-sonnet-5-5', 'biaya_usd' => 0.08]);
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.massal'), ['ids' => [$a->id, $b->id]])
            ->assertSessionHas('warn', fn ($m) => str_contains($m, 'melebihi sisa anggaran'));
        Queue::assertNothingPushed();

        AppSetting::put(AiBudget::KEY_BUDGET, '10');
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.massal'), ['ids' => [$a->id, $b->id, $c->id]])->assertSessionHas('ok');
        Queue::assertPushed(RunFolderAnalysis::class, 2);
        Queue::assertPushedOn('ai-bulk', RunFolderAnalysis::class);
        $this->assertNull($c->fresh()->verifikasi_status, 'Berkas selesai dilewati');

        // Analisa ulang (dicentang): berkas selesai ikut
        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.massal'), ['ids' => [$c->id], 'ulang' => 1])->assertSessionHas('ok');
        $this->assertSame('antre', $c->fresh()->verifikasi_status);

        $petugas = User::factory()->create(['is_active' => true])->assignRole('petugas');
        $this->actingAs($petugas)->post(route('verifikasi.latar.massal'), ['ids' => [$a->id]])->assertForbidden();
        $this->actingAs($petugas)->post(route('verifikasi.latar.jeda', $a))->assertForbidden();
    }

    public function test_anggaran_habis_saat_massal_berkas_sisa_dijeda(): void
    {
        [$f] = $this->berkas(1);
        $a = app(VerifikasiLatar::class)->mulai($f, $this->koordinator->id, queue: 'ai-bulk');
        app()->call([(new RunFolderAnalysis($a->id))->onQueue('ai-bulk'), 'handle']);
        AppSetting::put(AiBudget::KEY_BUDGET, '0.05');
        AiUsage::create(['jenis' => 'analisa', 'ref_id' => 999, 'model' => 'claude-sonnet-5-5', 'biaya_usd' => 0.06]);
        Http::fake();

        $this->langkah();
        Http::assertNothingSent();
        $f->refresh();
        $this->assertSame('dijeda', $f->verifikasi_status);
        $this->assertSame('Anggaran AI habis.', $f->verifikasi_pesan);
    }

    public function test_heartbeat_basi_menjadi_terhenti_dan_bisa_dilanjutkan(): void
    {
        [$f, $g] = $this->berkas(2);
        $this->mulai($f);
        $f->analyses()->first()->documents()->orderBy('id')->first()->update(['status' => ReviewStatus::Berjalan]);
        $f->update(['verifikasi_status' => 'berjalan', 'verifikasi_heartbeat_at' => now()->subMinutes(11)]);
        $g->update(['verifikasi_status' => 'berjalan', 'verifikasi_heartbeat_at' => now()->subMinutes(2)]);

        $this->assertSame('terhenti', $f->fresh()->statusVerifikasi());
        $this->assertSame('berjalan', $g->fresh()->statusVerifikasi());
        $j = $this->actingAs($this->koordinator)->getJson(route('verifikasi.latar.status', ['ids' => "$f->id,$g->id"]))->assertOk();
        $this->assertSame(['terhenti', 'berjalan'], collect($j->json('berkas'))->sortBy('id')->pluck('status')->all());
        $this->assertTrue($j->json('aktif'));
        $this->assertStringContainsString('Terhenti', collect($j->json('berkas'))->firstWhere('id', $f->id)['html']);
        $this->assertStringContainsString('Lanjutkan', collect($j->json('berkas'))->firstWhere('id', $f->id)['html']);

        $this->actingAs($this->koordinator)->post(route('verifikasi.latar.lanjut', $f))->assertSessionHas('ok');
        $this->assertSame('antre', $f->fresh()->verifikasi_status);
        $this->assertSame(0, $f->analyses()->first()->documents()->where('status', 'berjalan')->count(), 'File yang terputus diantrekan lagi');
        $this->assertSame(2, Queue::pushed(VerifikasiBerkas::class)->count());
    }
}
