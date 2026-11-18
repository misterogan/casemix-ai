<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hasil penarikan VClaim diterapkan ke data CERMAT: status BPJS per folder, evaluasi, notifikasi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->string('bpjs_status_kategori', 20)->nullable()->index();
            $t->dateTime('bpjs_status_at')->nullable();
            $t->boolean('bpjs_tindak_lanjut')->default(false)->index();
        });

        Schema::create('vclaim_evaluasi', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('ftp_folders')->cascadeOnDelete();
            $t->string('no_sep', 30)->index();
            $t->string('status_kategori', 20);
            $t->string('verdict_cermat', 20);
            $t->dateTime('dicatat_at');
            $t->unique(['folder_id', 'status_kategori']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->morphs('notifiable');
                $t->text('data');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vclaim_evaluasi');
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->dropIndex(['bpjs_status_kategori']);
            $t->dropIndex(['bpjs_tindak_lanjut']);
            $t->dropColumn(['bpjs_status_kategori', 'bpjs_status_at', 'bpjs_tindak_lanjut']);
        });
    }
};
