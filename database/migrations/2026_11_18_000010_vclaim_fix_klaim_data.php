<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Merapikan data vclaim_klaim lama: status_changed_at, status_teks "N#Teks", "-"/"" -> null. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vclaim_klaim')->whereNull('status_changed_at')->update(['status_changed_at' => DB::raw('first_seen_at')]);

        DB::table('vclaim_klaim')->where('status_teks', 'like', '%#%')->select('id', 'status_teks')->orderBy('id')
            ->each(function ($r) {
                if (preg_match('/^\d+#(.+)$/', trim($r->status_teks), $m)) {
                    DB::table('vclaim_klaim')->where('id', $r->id)->update(['status_teks' => trim($m[1])]);
                }
            });

        foreach (['no_fpk', 'poli'] as $kol) {
            DB::table('vclaim_klaim')->whereIn($kol, ['', '-'])->update([$kol => null]);
        }
    }

    public function down(): void {}
};
