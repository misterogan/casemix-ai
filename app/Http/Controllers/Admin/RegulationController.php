<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Regulation;
use App\Support\Audit;
use App\Support\VerificationRubric;
use Illuminate\Http\Request;

/** Acuan regulasi untuk butir Daftar periksa. Isi pasal diisi Admin; semua perubahan diaudit. */
class RegulationController extends Controller
{
    public function index()
    {
        return view('admin.regulations.index', ['regulations' => Regulation::orderBy('judul')->get()]);
    }

    public function create()
    {
        return view('admin.regulations.form', ['regulation' => new Regulation(['aktif' => true]), 'codes' => []]);
    }

    public function store(Request $request)
    {
        [$data, $codes] = $this->validated($request);
        $r = Regulation::create($data);
        $r->syncRubric($codes);
        Audit::log('admin.regulasi_buat', $r, "Menambah acuan regulasi {$r->judul}", ['data' => $data, 'butir' => $codes]);

        return redirect()->route('admin.regulations.index')->with('ok', 'Acuan regulasi ditambahkan.');
    }

    public function edit(Regulation $regulation)
    {
        return view('admin.regulations.form', ['regulation' => $regulation, 'codes' => $regulation->rubricCodes()]);
    }

    public function update(Request $request, Regulation $regulation)
    {
        [$data, $codes] = $this->validated($request);
        $lama = $regulation->only(array_keys($data)) + ['butir' => $regulation->rubricCodes()];
        $regulation->update($data);
        $regulation->syncRubric($codes);
        Audit::log('admin.regulasi_ubah', $regulation, "Mengubah acuan regulasi {$regulation->judul}",
            ['lama' => $lama, 'baru' => $data + ['butir' => $codes]]);

        return redirect()->route('admin.regulations.index')->with('ok', 'Acuan regulasi disimpan.');
    }

    public function destroy(Regulation $regulation)
    {
        $lama = $regulation->only(['judul', 'pasal']) + ['butir' => $regulation->rubricCodes()];
        $regulation->delete();
        Audit::log('admin.regulasi_hapus', null, "Menghapus acuan regulasi {$lama['judul']}", ['lama' => $lama]);

        return redirect()->route('admin.regulations.index')->with('ok', 'Acuan regulasi dihapus.');
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'judul' => ['required', 'string', 'max:255'],
            'pasal' => ['nullable', 'string', 'max:255'],
            'ringkasan' => ['required', 'string', 'max:5000'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'butir' => ['array'],
            'butir.*' => ['in:' . implode(',', VerificationRubric::codes())],
        ]);

        return [['judul' => $v['judul'], 'pasal' => $v['pasal'] ?? null, 'ringkasan' => $v['ringkasan'], 'url' => $v['url'] ?? null,
            'aktif' => $request->boolean('aktif')], $v['butir'] ?? []];
    }
}
