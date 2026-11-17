<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulations', function (Blueprint $t) {
            $t->id();
            $t->string('judul');
            $t->string('pasal')->nullable();
            $t->text('ringkasan');
            $t->string('url', 500)->nullable();
            $t->boolean('aktif')->default(true);
            $t->timestamps();
        });

        Schema::create('regulation_rubric', function (Blueprint $t) {
            $t->id();
            $t->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $t->string('rubric_code', 40);
            $t->unique(['regulation_id', 'rubric_code']);
            $t->index('rubric_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_rubric');
        Schema::dropIfExists('regulations');
    }
};
