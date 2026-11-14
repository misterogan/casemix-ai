<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Services\Ai\AiManager;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiProviderController extends Controller
{
    public function index()
    {
        return view('admin.providers.index', ['providers' => AiProvider::orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.providers.form', ['provider' => new AiProvider([
            'driver' => 'openai_compatible', 'max_tokens' => 4096, 'timeout' => 120, 'supports_images' => true, 'is_active' => true,
        ])]);
    }

    public function store(Request $request)
    {
        $provider = DB::transaction(function () use ($request) {
            $p = AiProvider::create($this->validated($request));
            $this->ensureSingleDefault($p);

            return $p;
        });
        Audit::log('admin.ai_buat', $provider, "Menambah penyedia AI {$provider->name}");

        return redirect()->route('admin.providers.index')->with('ok', "Penyedia {$provider->name} ditambahkan. Uji koneksinya sebelum dipakai.");
    }

    public function edit(AiProvider $provider)
    {
        return view('admin.providers.form', ['provider' => $provider]);
    }

    public function update(Request $request, AiProvider $provider)
    {
        $data = $this->validated($request, $provider);
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']); // kosong = tidak diganti
        }
        DB::transaction(function () use ($provider, $data) {
            $provider->update($data);
            $this->ensureSingleDefault($provider);
        });
        Audit::log('admin.ai_ubah', $provider, "Mengubah penyedia AI {$provider->name}");

        return redirect()->route('admin.providers.index')->with('ok', "Penyedia {$provider->name} disimpan.");
    }

    public function destroy(AiProvider $provider)
    {
        $provider->delete();
        Audit::log('admin.ai_hapus', null, "Menghapus penyedia AI {$provider->name}");

        return redirect()->route('admin.providers.index')->with('ok', 'Penyedia dihapus.');
    }

    public function test(AiProvider $provider, AiManager $ai)
    {
        $results = [];
        foreach (['model_fast' => 'Model cepat', 'model_standard' => 'Model standar'] as $field => $label) {
            $t = microtime(true);
            try {
                $r = $ai->for($provider)->ping($provider->$field);
                $results[] = "$label ({$provider->$field}): berhasil dalam " . round((microtime(true) - $t), 1) . ' detik.';
            } catch (\Throwable $e) {
                return back()->with('warn', "$label ({$provider->$field}) gagal: " . $e->getMessage());
            }
        }
        Audit::log('admin.ai_uji', $provider, "Menguji koneksi {$provider->name}");

        return back()->with('ok', implode(' ', $results));
    }

    private function validated(Request $request, ?AiProvider $p = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(array_keys(AiProvider::DRIVERS))],
            'base_url' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->driver === 'openai_compatible')],
            'api_key' => [$p ? 'nullable' : 'required_unless:driver,openai_compatible', 'nullable', 'string', 'max:500'],
            'model_fast' => ['required', 'string', 'max:150'],
            'model_standard' => ['required', 'string', 'max:150'],
            'max_tokens' => ['required', 'integer', 'min:512', 'max:64000'],
            // 3 percobaan × 580 detik masih di bawah timeout job pemeriksaan (1800 detik)
            'timeout' => ['required', 'integer', 'min:10', 'max:580'],
            'price_input_usd' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_with:price_output_usd'],
            'price_output_usd' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_with:price_input_usd'],
            // Wajib: dasar kepatuhan UU PDP karena berkas pasien dikirim ke penyedia ini
            'data_location' => ['required', 'string', 'min:5', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['base_url.required' => 'Base URL wajib untuk penyedia OpenAI-compatible, misalnya https://…/v1']);

        foreach (['supports_images', 'is_active', 'is_default'] as $b) {
            $data[$b] = $request->boolean($b);
        }

        return $data;
    }

    private function ensureSingleDefault(AiProvider $p): void
    {
        if ($p->is_default) {
            AiProvider::whereKeyNot($p->id)->update(['is_default' => false]);
        }
    }
}
