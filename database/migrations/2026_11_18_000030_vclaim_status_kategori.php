<?php

use App\Models\VclaimKlaim;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kategori status klaim dari status_teks (Klaim Tidak Layak terpisah dari Klaim Pending di status 2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vclaim_klaim', function (Blueprint $t) {
            $t->string('status_kategori', 20)->default('lainnya')->index();
        });
        Schema::table('vclaim_klaim_status_history', function (Blueprint $t) {
            $t->string('kategori_lama', 20)->nullable();
            $t->string('kategori_baru', 20)->nullable();
        });

        DB::table('vclaim_klaim')->select('id', 'status_kode', 'status_teks')->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $r) {
                    DB::table('vclaim_klaim')->where('id', $r->id)
                        ->update(['status_kategori' => VclaimKlaim::kategori($r->status_teks, (int) $r->status_kode)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('vclaim_klaim_status_history', fn (Blueprint $t) => $t->dropColumn(['kategori_lama', 'kategori_baru']));
        Schema::table('vclaim_klaim', function (Blueprint $t) {
            $t->dropIndex(['status_kategori']);
            $t->dropColumn('status_kategori');
        });
    }
};
