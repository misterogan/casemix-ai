<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Verifikasi langsung dari FTP: indeks folder pasien (disinkronkan otomatis tiap jam) dan hasil analisa AI per folder.
 * Tidak ada salinan dokumen yang disimpan: file hanya diunduh sementara untuk dibaca AI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ftp_folders', function (Blueprint $table) {
            $table->id();
            $table->string('path', 500)->unique();
            $table->string('name', 255);
            $table->string('no_rm', 30)->nullable()->index();
            $table->string('nama_pasien')->nullable();
            $table->string('no_sep', 30)->nullable()->index();
            $table->string('jenis_rawat', 10)->nullable();
            $table->json('files');                      // [{name, path, size, modified}]
            $table->unsignedInteger('file_count')->default(0);
            $table->string('fingerprint', 64);          // sidik isi folder: nama + ukuran + waktu ubah setiap file
            $table->timestamp('files_modified_at')->nullable()->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_at')->nullable(); // folder sudah tidak ada di FTP
            // Ringkasan analisa terakhir, untuk daftar dan filter
            $table->unsignedBigInteger('last_analysis_id')->nullable();
            $table->string('analysis_status', 20)->nullable()->index();
            $table->string('analyzed_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('kritis_count')->default(0);
            $table->unsignedSmallInteger('perhatian_count')->default(0);
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('folder_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ftp_folder_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('antre')->index();
            $table->string('fingerprint', 64)->nullable();
            $table->json('files_snapshot')->nullable();
            $table->text('summary')->nullable();
            $table->json('findings')->nullable();       // temuan AI dan aturan: {tingkat, judul, uraian, kategori, dokumen, halaman, sumber}
            $table->json('suggestions')->nullable();    // kondisi untuk dikonfirmasi ke DPJP
            $table->json('extracted')->nullable();      // isian yang terbaca dari dokumen (dasar aturan E-Klaim)
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });

        // Hak akses: Koordinator membaca hasil verifikasi dan meminta analisa ulang.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['folders.view', 'folders.analyze'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('koordinator', 'web')->givePermissionTo(['folders.view', 'folders.analyze']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_analyses');
        Schema::dropIfExists('ftp_folders');
        Permission::whereIn('name', ['folders.view', 'folders.analyze'])->delete();
    }
};
