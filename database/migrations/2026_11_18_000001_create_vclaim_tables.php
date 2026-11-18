<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vclaim_kunjungan', function (Blueprint $t) {
            $t->id();
            $t->string('no_sep', 30)->unique();
            $t->date('tgl_sep')->nullable()->index();
            $t->date('tgl_plg_sep')->nullable();
            $t->string('jns_pelayanan', 20)->nullable();
            $t->string('kelas_rawat', 20)->nullable();
            $t->string('poli', 100)->nullable();
            $t->string('diagnosa', 255)->nullable();
            $t->string('no_kartu', 20)->nullable()->index();
            $t->string('no_rujukan', 40)->nullable();
            $t->string('nama', 150)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vclaim_klaim', function (Blueprint $t) {
            $t->id();
            $t->string('no_sep', 30)->unique();
            $t->unsignedTinyInteger('status_kode')->index();
            $t->string('status_teks', 50)->nullable();
            $t->date('tgl_sep')->nullable();
            $t->date('tgl_pulang')->nullable()->index();
            $t->string('jns_pelayanan', 20)->nullable();
            $t->string('kelas_rawat', 20)->nullable();
            $t->string('poli', 100)->nullable();
            $t->string('no_fpk', 40)->nullable();
            $t->string('inacbg_kode', 20)->nullable();
            $t->string('inacbg_nama', 255)->nullable();
            foreach (['by_pengajuan', 'by_setujui', 'by_tarif_gruper', 'by_tarif_rs', 'by_topup'] as $c) {
                $t->decimal($c, 15, 2)->nullable();
            }
            $t->string('no_kartu', 20)->nullable()->index();
            $t->string('no_mr', 30)->nullable();
            $t->string('nama', 150)->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vclaim_klaim_status_history', function (Blueprint $t) {
            $t->id();
            $t->string('no_sep', 30)->index();
            $t->unsignedTinyInteger('status_lama')->nullable();
            $t->unsignedTinyInteger('status_baru');
            $t->timestamp('terlihat_at')->index();
        });

        Schema::create('vclaim_histori', function (Blueprint $t) {
            $t->id();
            $t->string('no_kartu', 20)->index();
            $t->string('no_sep', 30);
            $t->date('tgl_sep')->nullable();
            $t->date('tgl_plg_sep')->nullable();
            $t->string('jns_pelayanan', 20)->nullable();
            $t->string('kelas_rawat', 20)->nullable();
            $t->text('diagnosa')->nullable();
            $t->string('ppk_pelayanan', 150)->nullable();
            $t->string('poli', 100)->nullable();
            $t->string('no_rujukan', 40)->nullable();
            $t->timestamp('fetched_at')->nullable();
            $t->timestamps();
            $t->unique(['no_kartu', 'no_sep']);
        });

        Schema::create('vclaim_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->string('jenis', 20)->index();
            $t->date('tanggal')->nullable();
            $t->string('jns', 5)->nullable();
            $t->string('status', 10);
            $t->unsignedInteger('jumlah')->default(0);
            $t->string('error', 500)->nullable();
            $t->unsignedInteger('durasi_ms')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['vclaim_sync_runs', 'vclaim_histori', 'vclaim_klaim_status_history', 'vclaim_klaim', 'vclaim_kunjungan'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
