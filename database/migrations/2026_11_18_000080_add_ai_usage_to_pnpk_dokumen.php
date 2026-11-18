<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolom pencatatan biaya yang dipakai AiBudget (sama dengan sumber lain): OCR + usulan butir PNPK.
        Schema::table('pnpk_dokumen', function (Blueprint $t) {
            $t->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('tokens_in')->nullable();
            $t->unsignedInteger('tokens_out')->nullable();
            $t->decimal('cost_usd', 12, 6)->nullable();
            $t->timestamp('finished_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('pnpk_dokumen', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ai_provider_id');
            $t->dropColumn(['model', 'tokens_in', 'tokens_out', 'cost_usd', 'finished_at']);
        });
    }
};
