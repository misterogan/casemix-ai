<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);          // ftp
            $table->string('remote_dir')->nullable();
            $table->string('status', 20)->default('antre'); // antre | memilah | siap_ditinjau | disimpan | gagal
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('remote_path');
            $table->unsignedBigInteger('remote_size')->nullable();
            $table->string('local_path')->nullable();
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->char('sha256', 64)->nullable()->index();
            $table->string('detected_type', 30)->nullable();
            $table->json('detected')->nullable();   // nama, no_rm, no_sep, tanggal, keyakinan
            $table->string('group_key')->nullable()->index();
            $table->foreignId('claim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('antre'); // antre | dipilah | gagal | disimpan | dilewati
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['remote_path', 'remote_size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_items');
        Schema::dropIfExists('import_batches');
    }
};
