<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anggaran AI bulanan: pengaturan yang bisa diubah Admin, harga per penyedia,
 * dan perkiraan biaya setiap pemanggilan AI (dihitung saat itu, supaya tidak berubah bila harga diubah kemudian).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('ai_providers', function (Blueprint $table) {
            // USD per 1 juta token. Kosong = pakai harga bawaan dari config/casemix.php sesuai nama model.
            $table->decimal('price_input_usd', 10, 4)->nullable()->after('timeout');
            $table->decimal('price_output_usd', 10, 4)->nullable()->after('price_input_usd');
        });

        foreach (['ai_reviews', 'claim_extractions'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->decimal('cost_usd', 12, 6)->nullable()->after('tokens_out');
                $table->index('finished_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_reviews', 'claim_extractions'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['finished_at']);
                $table->dropColumn('cost_usd');
            });
        }
        Schema::table('ai_providers', fn (Blueprint $table) => $table->dropColumn(['price_input_usd', 'price_output_usd']));
        Schema::dropIfExists('app_settings');
    }
};
