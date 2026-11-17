<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_suggestions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_analysis_id')->constrained()->cascadeOnDelete();
            $t->string('system', 10); // icd10|icd9cm
            $t->string('peran', 10); // utama|sekunder|tindakan
            $t->string('code', 10);
            $t->string('title');
            $t->string('alasan', 500)->nullable();
            $t->string('kaidah_koding', 300)->nullable();
            $t->string('sumber', 12)->default('lainnya'); // resume|cppt|penunjang|lab|lainnya
            $t->string('bukti', 400);
            $t->foreignId('folder_document_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('halaman');
            $t->string('keyakinan', 6)->default('sedang'); // kuat|sedang
            $t->boolean('tercantum_di_resume')->default(false);
            $t->string('status_tindak_lanjut', 15)->nullable(); // ditanyakan|setuju|tidak_setuju|tidak_relevan
            $t->timestamps();
            $t->index(['folder_analysis_id', 'peran']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_suggestions');
    }
};
