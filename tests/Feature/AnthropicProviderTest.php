<?php

namespace Tests\Feature;

use App\Jobs\ReadFolderDocument;
use App\Models\AiProvider;
use App\Services\Ai\AiRequest;
use App\Services\Ai\Providers\AnthropicProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    use RefreshDatabase;

    private AnthropicProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $config = AiProvider::create(['name' => 'Claude', 'driver' => 'anthropic', 'api_key' => 'k', 'model_fast' => 'claude-haiku-5-5',
            'model_standard' => 'claude-sonnet-5-5', 'is_active' => true, 'is_default' => true, 'data_location' => 'Uji'])->fresh();
        $this->provider = new AnthropicProvider($config);
    }

    private function ok(array $content = [['type' => 'text', 'text' => '{}']], string $stop = 'end_turn', int $out = 10): array
    {
        return ['id' => 'x', 'model' => 'claude-sonnet-5-5', 'stop_reason' => $stop, 'usage' => ['input_tokens' => 10, 'output_tokens' => $out], 'content' => $content];
    }

    private function ask(): \App\Services\Ai\AiResponse
    {
        return $this->provider->generate((new AiRequest('claude-sonnet-5-5', maxTokens: 100))->text('hai'));
    }

    public function test_payload_mematikan_thinking(): void
    {
        Http::fake(['*/v1/messages' => Http::response($this->ok())]);

        $this->ask();

        Http::assertSent(fn (HttpRequest $r) => $r['thinking'] === ['type' => 'disabled'] && ! isset($r['output_config']));
    }

    public function test_effort_rendah_bila_dipilih(): void
    {
        config(['casemix.ai.thinking' => 'low']);
        Http::fake(['*/v1/messages' => Http::response($this->ok())]);

        $this->ask();

        Http::assertSent(fn (HttpRequest $r) => $r['output_config'] === ['effort' => 'low'] && ! isset($r['thinking']));
    }

    public function test_400_soal_thinking_diulang_sekali_tanpa_parameter_dan_diingat(): void
    {
        Http::fake(['*/v1/messages' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'thinking: Extra inputs are not permitted']], 400)
            ->push($this->ok())
            ->push($this->ok())]);

        $this->ask();
        $this->assertCount(2, Http::recorded(), 'Tepat satu kali ulang');
        $this->assertFalse(isset(Http::recorded()[1][0]['thinking']));

        // Panggilan berikutnya untuk model yang sama langsung tanpa parameter (disimpan di cache)
        $this->ask();
        $this->assertCount(3, Http::recorded());
        $this->assertFalse(isset(Http::recorded()[2][0]['thinking']));
    }

    public function test_400_lain_tidak_diulang(): void
    {
        Http::fake(['*/v1/messages' => Http::response(['error' => ['message' => 'max_tokens: too large']], 400)]);

        try {
            $this->ask();
            $this->fail('Harus gagal');
        } catch (\App\Services\Ai\AiException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
        }
        $this->assertCount(1, Http::recorded());
    }

    public function test_pesan_max_tokens_memuat_token_berpikir(): void
    {
        Http::fake(['*/v1/messages' => Http::response($this->ok([
            ['type' => 'thinking', 'thinking' => 'menimbang...'],
            ['type' => 'text', 'text' => '{"isian":['],
        ], 'max_tokens', 100))]);

        $res = $this->ask();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blok: thinking, text; output_tokens: 100; panjang teks jawaban: 10 karakter');
        ReadFolderDocument::ensureComplete($res, 100);
    }
}
