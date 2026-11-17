<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Jobs\ArchiveFolderFiles;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
use App\Services\Verification\FolderSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutoArchiveFtp extends SimrsFtp
{
    public array $folders = [];

    public array $downloads = [];

    public function configured(): bool
    {
        return true;
    }

    public function listPatientFolders(): Collection
    {
        return collect($this->folders);
    }

    public function download(string $remotePath, string $localRelative): string
    {
        $this->downloads[] = $remotePath;
        Storage::disk(config('casemix.storage_disk'))->put($localRelative, 'isi ' . $remotePath);

        return $localRelative;
    }
}

/** Arsip otomatis saat sinkron FTP (tanpa AI) dan pemusnahan salinan. */
class AutoArchiveTest extends TestCase
{
    use RefreshDatabase;

    private AutoArchiveFtp $ftp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('casemix.storage_disk'));
        $this->seed(RolePermissionSeeder::class);
        $this->ftp = new AutoArchiveFtp;
        $this->app->instance(SimrsFtp::class, $this->ftp);
        config(['casemix.archive.enabled' => true, 'casemix.archive.auto_enabled' => true]);
    }

    private function ftpFolder(string $sep, int $mtime = 1000): array
    {
        return ['path' => "/ftp/data/X_$sep", 'folder' => "X_$sep", 'no_rm' => '1', 'nama' => 'A', 'no_sep' => $sep, 'jenis_rawat' => 'RJ',
            'files' => [['name' => 'catatan.txt', 'path' => "/ftp/data/X_$sep/catatan.txt", 'size' => 10, 'modified' => $mtime]]];
    }

    public function test_folder_baru_didispatch_folder_lama_tidak(): void
    {
        $this->ftp->folders = [$this->ftpFolder('SEPLAMA')];
        Queue::fake();
        app(FolderSync::class)->run();
        Queue::assertPushed(ArchiveFolderFiles::class, 1);

        Queue::fake();
        $this->ftp->folders[] = $this->ftpFolder('SEPBARU');
        app(FolderSync::class)->run();
        $baru = FtpFolder::where('no_sep', 'SEPBARU')->first();
        Queue::assertPushed(ArchiveFolderFiles::class, 1);
        Queue::assertPushed(ArchiveFolderFiles::class, fn ($j) => $j->folderId === $baru->id);

        Queue::fake();
        $this->ftp->folders[0] = $this->ftpFolder('SEPLAMA', 2000); // fingerprint berubah
        app(FolderSync::class)->run();
        Queue::assertPushed(ArchiveFolderFiles::class, 1);
        Queue::assertPushed(ArchiveFolderFiles::class, fn ($j) => $j->folderId === FtpFolder::where('no_sep', 'SEPLAMA')->value('id'));
    }

    public function test_auto_mati_tidak_didispatch(): void
    {
        config(['casemix.archive.auto_enabled' => false]);
        Queue::fake();
        $this->ftp->folders = [$this->ftpFolder('SEPA')];
        app(FolderSync::class)->run();
        Queue::assertNotPushed(ArchiveFolderFiles::class);
    }

    public function test_salinan_tersimpan_dan_dipakai_tahap_1(): void
    {
        $this->ftp->folders = [$this->ftpFolder('SEPA')];
        app(FolderSync::class)->run(); // queue sync: job langsung jalan
        $d = FolderDocument::firstOrFail();
        $this->assertSame(ReviewStatus::Diarsipkan, $d->status);
        $this->assertNull($d->folder_analysis_id);
        $this->assertSame(hash('sha256', 'isi /ftp/data/X_SEPA/catatan.txt'), $d->stored_sha256);
        Storage::disk(config('casemix.storage_disk'))->assertExists($d->stored_path);
        $this->assertCount(1, $this->ftp->downloads);

        // Dijalankan lagi: sha256 sudah tersimpan, tidak diunduh ulang
        (new ArchiveFolderFiles($d->ftp_folder_id))->handle($this->ftp);
        $this->assertCount(1, $this->ftp->downloads);

        // Status diarsipkan tidak dihitung sebagai sudah dianalisa
        $folder = FtpFolder::first();
        $this->assertNull($folder->analysis_status);
        $this->assertSame(1, FtpFolder::query()->state('belum')->count());
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $counts = $this->actingAs($koor)->get(route('folders.index'))->assertOk()->viewData('counts');
        $this->assertSame(['semua' => 1, 'sudah' => 0, 'belum' => 1], $counts);

        // Tahap 1: diarsipkan bukan hasil baca yang dipakai ulang, dan isi diambil dari salinan tanpa unduh ulang
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre]);
        app(FolderAnalyzer::class)->prepare($a);
        $doc = $a->documents()->first();
        $this->assertSame(ReviewStatus::Antre, $doc->status);
        $this->assertFalse($doc->reused);
        app(FolderAnalyzer::class)->documentRequest($doc, new AiProvider(['model_fast' => 'm', 'model_standard' => 'm']));
        $this->assertCount(1, $this->ftp->downloads);
        $this->assertSame($d->stored_path, $doc->fresh()->stored_path);
    }

    public function test_folder_terlalu_besar_dilewati(): void
    {
        config(['casemix.archive.auto_max_folder_mb' => 1]);
        $f = $this->ftpFolder('SEPBESAR');
        $f['files'][0]['size'] = 2 * 1024 * 1024;
        $this->ftp->folders = [$f];
        app(FolderSync::class)->run();
        $this->assertSame(0, FolderDocument::count());
        $this->assertCount(0, $this->ftp->downloads);
    }

    public function test_pemusnahan_hanya_yang_belum_dianalisa(): void
    {
        $this->ftp->folders = [$this->ftpFolder('SEPBELUM'), $this->ftpFolder('SEPSUDAH')];
        app(FolderSync::class)->run();
        $sudah = FtpFolder::where('no_sep', 'SEPSUDAH')->first();
        $sudah->update(['analyzed_at' => now(), 'analysis_status' => 'selesai']);
        $disk = Storage::disk(config('casemix.storage_disk'));
        $belumDoc = FolderDocument::where('ftp_folder_id', FtpFolder::where('no_sep', 'SEPBELUM')->value('id'))->first();
        $sudahDoc = FolderDocument::where('ftp_folder_id', $sudah->id)->first();
        $path = $belumDoc->stored_path;

        $this->travel(2)->months();
        $this->artisan('casemix:archive-prune')->assertSuccessful();
        $this->assertNotNull($belumDoc->fresh()->stored_path);

        $this->travel(2)->months(); // lewat 3 bulan
        $this->artisan('casemix:archive-prune')->assertSuccessful();
        $b = $belumDoc->fresh();
        $this->assertNull($b->stored_path);
        $this->assertNull($b->stored_sha256);
        $this->assertNull($b->stored_at);
        $disk->assertMissing($path);
        $this->assertNotNull($sudahDoc->fresh()->stored_path); // sudah dianalisa: masa simpan lama
        $disk->assertExists($sudahDoc->stored_path);

        $this->assertSame(2, AuditLog::where('action', 'arsip.hapus_kedaluwarsa')->count()); // satu entri per jalan
        $log = AuditLog::where('action', 'arsip.hapus_kedaluwarsa')->latest('id')->first();
        $this->assertSame(1, $log->properties['files']);
        $this->assertSame(1, $log->properties['folders']);
        $this->assertSame(strlen('isi /ftp/data/X_SEPBELUM/catatan.txt'), $log->properties['bytes']);
        $this->assertSame(1, $log->properties['kategori']['belum_dianalisa']['files']);
        $this->assertSame(0, $log->properties['kategori']['sudah_dianalisa']['files']);
    }

    public function test_tab_dipindah_tanpa_diperiksa_chip_salinan(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(10, 0));
        $this->ftp->folders = [$this->ftpFolder('SEPHILANG')];
        app(FolderSync::class)->run();
        $this->ftp->folders = [];
        app(FolderSync::class)->run();
        $koor = User::factory()->create(['is_active' => true])->assignRole('koordinator');
        $this->actingAs($koor)->get(route('folders.index', ['tab' => 'hilang_tanpa_periksa']))->assertOk()
            ->assertSee('SEPHILANG')->assertSee('Salinan tersedia s.d. 17 Feb 2027');
        $this->actingAs($koor)->get(route('folders.show', FtpFolder::first()))->assertOk()->assertSee('Analisa ulang dengan AI');
    }
}
