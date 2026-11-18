<?php

namespace App\Http\Controllers;

use App\Jobs\EkstrakPnpk;
use App\Models\RegulasiButir;
use App\Models\RegulasiDokumen;
use App\Models\RegulasiIcd;
use App\Services\Pnpk\PnpkVerifier;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * PNPK sebagai bagian Acuan regulasi. Kelola dokumen (bukan data pasien): Admin & Koordinator.
 * Tinjau butir/ICD: Koordinator saja. PDF resmi = sumber kebenaran; hanya butir disetujui yang dipakai.
 */
class PnpkController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $tab = isset(RegulasiDokumen::TAB[$request->query('tab')]) ? $request->query('tab') : 'semua';
        $list = RegulasiDokumen::query()->tab($tab)->with('penggantiDokumen:id,judul')
            ->withCount(['icd as icd_disetujui' => fn ($w) => $w->where('status', 'disetujui'),
                'butir as butir_disetujui' => fn ($w) => $w->where('status', 'disetujui'),
                'butir as butir_belum_diperiksa' => fn ($w) => $w->where('status', 'disetujui')->where('diperiksa_manusia', false)])
            ->when(isset(RegulasiDokumen::STATUS[$status]), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('judul', 'like', "%$q%")->orWhere('nomor', 'like', "%$q%")->orWhere('nomor_kmk', 'like', "%$q%")
                ->when(DB::getDriverName() === 'mysql', fn ($m) => $m->orWhereRaw('MATCH(judul, nomor) AGAINST (? IN NATURAL LANGUAGE MODE)', [$q]))
                ->orWhereHas('icd', fn ($i) => $i->where('status', 'disetujui')->where('kode', 'like', RegulasiIcd::normalize($q) . '%'))))
            ->orderByDesc('tahun')->orderBy('judul')->paginate(25)->withQueryString();

        $isi = trim((string) $request->query('isi', ''));

        return view('acuan.pnpk.index', ['list' => $list, 'q' => $q, 'status' => $status, 'isi' => $isi, 'tab' => $tab,
            'hasil' => $isi !== '' ? $this->cariIsi($isi) : collect()]);
    }

    public function create()
    {
        $jenis = isset(RegulasiDokumen::JENIS[request('jenis')]) ? request('jenis') : 'kmk_pnpk';

        return view('acuan.pnpk.form', ['dokumen' => new RegulasiDokumen(['status' => 'berlaku', 'jenis' => $jenis]), 'pilihan' => $this->pilihanGanti()]);
    }

    public function store(Request $request)
    {
        $v = $request->validate($this->rules() + [
            'pdf' => ['required', 'file', 'max:51200'],
            'menggantikan_id' => ['nullable', 'integer', 'exists:regulasi_dokumen,id'],
        ]);
        $file = $request->file('pdf');
        $fh = fopen($file->getRealPath(), 'rb');
        $head = (string) fread($fh, 5);
        fclose($fh);
        if ($head !== '%PDF-') {
            throw ValidationException::withMessages(['pdf' => 'File bukan PDF.']);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        if (RegulasiDokumen::where('sha256', $sha)->exists()) {
            throw ValidationException::withMessages(['pdf' => 'PDF yang sama sudah pernah diunggah.']);
        }
        $path = "pnpk/$sha.pdf";
        Storage::disk('local')->putFileAs('pnpk', $file, "$sha.pdf");

        $d = DB::transaction(function () use ($v, $path, $sha, $request) {
            $d = RegulasiDokumen::create($this->meta($v) + ['status' => 'berlaku', 'path' => $path, 'sha256' => $sha,
                'status_proses' => 'antre', 'diunggah_oleh' => $request->user()->id]);
            if ($lama = isset($v['menggantikan_id']) ? RegulasiDokumen::find($v['menggantikan_id']) : null) {
                $lama->update(['status' => 'diganti', 'diganti_oleh_id' => $d->id]);
                Audit::log('pnpk.ganti_versi', $lama, "PNPK {$lama->judul} digantikan oleh {$d->judul}", ['lama' => $lama->id, 'baru' => $d->id]);
            }
            $this->cabut($d);

            return $d;
        });
        Audit::log('pnpk.unggah', $d, "Mengunggah {$d->jenisLabel()} {$d->judul}", ['data' => $this->meta($v), 'sha256' => $sha]);
        EkstrakPnpk::dispatch($d->id);

        return redirect()->route('acuan.pnpk.show', $d)->with('ok', 'Dokumen diunggah. Teks sedang diekstrak, lalu AI menyusun usulan untuk ditinjau.');
    }

    public function edit(RegulasiDokumen $pnpk)
    {
        return view('acuan.pnpk.form', ['dokumen' => $pnpk, 'pilihan' => collect(),
            'penggantiPilihan' => RegulasiDokumen::whereKeyNot($pnpk->id)->orderBy('judul')->get(['id', 'judul', 'nomor', 'nomor_kmk', 'tahun'])]);
    }

    public function update(Request $request, RegulasiDokumen $pnpk)
    {
        $v = $request->validate($this->rules() + ['status' => ['required', 'in:berlaku,dicabut,diganti'],
            'diganti_oleh_id' => ['nullable', 'integer', 'exists:regulasi_dokumen,id', 'not_in:' . $pnpk->id],
            'mencabut_id' => ['nullable', 'integer', 'exists:regulasi_dokumen,id', 'not_in:' . $pnpk->id]]);
        $ganti = $v['diganti_oleh_id'] ?? null;
        $status = $ganti ? 'diganti' : ($v['status'] === 'diganti' && ! $pnpk->diganti_oleh_id ? $pnpk->status : $v['status']);
        $data = $this->meta($v) + ['status' => $status, 'diganti_oleh_id' => $ganti ?? ($status === 'diganti' ? $pnpk->diganti_oleh_id : null),
            'perlu_dilengkapi' => false];
        $lama = $pnpk->only(array_keys($data));
        $pnpk->update($data);
        Audit::log('pnpk.ubah', $pnpk, "Mengubah metadata {$pnpk->jenisLabel()} {$pnpk->judul}", ['lama' => $lama, 'baru' => $data]);
        $this->cabut($pnpk);

        return redirect()->route('acuan.pnpk.show', $pnpk)->with('ok', 'Metadata disimpan.');
    }

    public function destroy(RegulasiDokumen $pnpk)
    {
        $lama = $pnpk->only(['judul', 'nomor_kmk', 'tahun', 'sha256']);
        Storage::disk('local')->delete($pnpk->path);
        $pnpk->delete();
        Audit::log('pnpk.hapus', null, "Menghapus PNPK {$lama['judul']}", ['lama' => $lama]);

        return redirect()->route('acuan.pnpk.index')->with('ok', 'PNPK dihapus.');
    }

    public function show(Request $request, RegulasiDokumen $pnpk)
    {
        $pnpk->load(['icd.dokumen', 'butir', 'penggantiDokumen', 'digantikan', 'mencabutDokumen', 'pencabut']);

        return view('acuan.pnpk.show', ['dokumen' => $pnpk, 'bolehTinjau' => $request->user()->hasRole('koordinator'),
            'alasanBelumSelesai' => self::alasanBelumSelesai($pnpk),
            'perJenis' => $pnpk->butir->groupBy('jenis')]);
    }

    public function pdf(RegulasiDokumen $pnpk)
    {
        abort_unless(Storage::disk('local')->exists($pnpk->path), 404);

        return response()->file(Storage::disk('local')->path($pnpk->path), ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="pnpk-' . $pnpk->id . '.pdf"', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ------------------------------------------------------------ tinjau (Koordinator)

    public function butir(Request $request, RegulasiDokumen $pnpk, RegulasiButir $butir)
    {
        abort_unless($butir->dokumen_id === $pnpk->id, 404);
        $v = $request->validate(['aksi' => ['required', 'in:setujui,tolak,ringkasan,konfirmasi'], 'alasan' => ['required_if:aksi,tolak', 'nullable', 'string', 'max:255'],
            'ringkasan' => ['required_if:aksi,ringkasan', 'nullable', 'string', 'max:300']]);
        $lama = $butir->only(['status', 'ringkasan', 'alasan_tolak', 'diperiksa_manusia']);
        $tinjau = ['ditinjau_oleh' => $request->user()->id, 'ditinjau_at' => now(), 'diperiksa_manusia' => true];
        match ($v['aksi']) {
            'setujui' => $butir->cocok_kutipan
                ? $butir->update(['status' => 'disetujui', 'alasan_tolak' => null] + $tinjau)
                : throw ValidationException::withMessages(['aksi' => PnpkVerifier::TOLAK_KUTIPAN . '; butir tidak bisa disetujui.']),
            'tolak' => $butir->update(['status' => 'ditolak', 'alasan_tolak' => $v['alasan']] + $tinjau),
            'ringkasan' => $butir->update(['ringkasan' => trim($v['ringkasan'])] + $tinjau),
            // Konfirmasi persetujuan otomatis: isi tidak berubah, hanya ditandai sudah diperiksa manusia.
            'konfirmasi' => $butir->status === 'disetujui'
                ? $butir->update($tinjau)
                : throw ValidationException::withMessages(['aksi' => 'Hanya butir disetujui yang bisa dikonfirmasi.']),
        };
        Audit::log('pnpk.butir_' . $v['aksi'], $butir, "Butir PNPK {$pnpk->judul} hal. {$butir->halaman}: {$v['aksi']}",
            ['lama' => $lama, 'baru' => $butir->only(['status', 'ringkasan', 'alasan_tolak', 'diperiksa_manusia'])]);

        return $this->balik($request, ['status' => $butir->status, 'ringkasan' => $butir->ringkasan, 'diperiksa_manusia' => true]);
    }

    public function butirMassal(Request $request, RegulasiDokumen $pnpk)
    {
        $v = $request->validate(['jenis' => ['required', 'in:' . implode(',', array_keys(RegulasiButir::JENIS))], 'aksi' => ['nullable', 'in:setujui,konfirmasi']]);
        $tinjau = ['ditinjau_oleh' => $request->user()->id, 'ditinjau_at' => now(), 'diperiksa_manusia' => true];
        if (($v['aksi'] ?? 'setujui') === 'konfirmasi') {
            $ids = $pnpk->butir()->where('jenis', $v['jenis'])->where('status', 'disetujui')->where('diperiksa_manusia', false)->pluck('id')->all();
            RegulasiButir::whereIn('id', $ids)->update($tinjau);
            Audit::log('pnpk.butir_konfirmasi_massal', $pnpk, "Mengonfirmasi " . count($ids) . " butir {$v['jenis']} PNPK {$pnpk->judul}", ['butir' => $ids]);

            return back()->with('ok', count($ids) . ' butir dikonfirmasi.');
        }
        $ids = $pnpk->butir()->where('jenis', $v['jenis'])->where('status', 'usulan')->where('cocok_kutipan', true)->pluck('id')->all();
        RegulasiButir::whereIn('id', $ids)->update(['status' => 'disetujui'] + $tinjau);
        Audit::log('pnpk.butir_setujui_massal', $pnpk, "Menyetujui " . count($ids) . " butir {$v['jenis']} PNPK {$pnpk->judul}", ['butir' => $ids]);

        return back()->with('ok', count($ids) . ' butir disetujui.');
    }

    public function icd(Request $request, RegulasiDokumen $pnpk, RegulasiIcd $icd)
    {
        abort_unless($icd->dokumen_id === $pnpk->id, 404);
        $v = $request->validate(['aksi' => ['required', 'in:setujui,tolak,konfirmasi'], 'alasan' => ['nullable', 'string', 'max:255']]);
        if ($v['aksi'] === 'konfirmasi') {
            if ($icd->status !== 'disetujui') {
                throw ValidationException::withMessages(['aksi' => 'Hanya ICD disetujui yang bisa dikonfirmasi.']);
            }
            $icd->update(['diperiksa_manusia' => true, 'ditinjau_oleh' => $request->user()->id, 'ditinjau_at' => now()]);
            Audit::log('pnpk.icd_konfirmasi', $icd, "Konfirmasi ICD {$icd->kode} PNPK {$pnpk->judul}");

            return $this->balik($request, ['status' => $icd->status, 'diperiksa_manusia' => true]);
        }
        if ($v['aksi'] === 'setujui' && ! RegulasiIcd::adaDiMaster($icd->kode)) {
            throw ValidationException::withMessages(['aksi' => PnpkVerifier::TOLAK_ICD]);
        }
        $lama = $icd->status;
        $icd->update(['status' => $v['aksi'] === 'setujui' ? 'disetujui' : 'ditolak', 'alasan_tolak' => $v['aksi'] === 'tolak' ? ($v['alasan'] ?? null) : null,
            'ditinjau_oleh' => $request->user()->id, 'ditinjau_at' => now(), 'diperiksa_manusia' => true]);
        Audit::log('pnpk.icd_' . $v['aksi'], $icd, "ICD {$icd->kode} PNPK {$pnpk->judul}: {$v['aksi']}", ['lama' => $lama, 'baru' => $icd->status]);

        return $this->balik($request, ['status' => $icd->status]);
    }

    public function icdTambah(Request $request, RegulasiDokumen $pnpk)
    {
        $v = $request->validate(['kode' => ['required', 'string', 'max:12']]);
        $kode = RegulasiIcd::normalize($v['kode']);
        if (! RegulasiIcd::adaDiMaster($kode)) {
            throw ValidationException::withMessages(['kode' => PnpkVerifier::TOLAK_ICD]);
        }
        $icd = RegulasiIcd::updateOrCreate(['dokumen_id' => $pnpk->id, 'kode' => $kode],
            ['sumber' => 'manual', 'status' => 'disetujui', 'diperiksa_manusia' => true, 'alasan_tolak' => null, 'ditinjau_oleh' => $request->user()->id, 'ditinjau_at' => now()]);
        Audit::log('pnpk.icd_setujui', $icd, "Menambah ICD {$kode} pada PNPK {$pnpk->judul}", ['sumber' => 'manual']);

        return back()->with('ok', "ICD $kode ditambahkan.");
    }

    public function selesai(Request $request, RegulasiDokumen $pnpk)
    {
        if ($alasan = self::alasanBelumSelesai($pnpk)) {
            throw ValidationException::withMessages(['aksi' => $alasan]);
        }
        $pnpk->update(['status_proses' => 'selesai']);
        Audit::log('pnpk.selesai_tinjau', $pnpk, "Selesai meninjau PNPK {$pnpk->judul}");

        return redirect()->route('acuan.pnpk.index')->with('ok', 'PNPK selesai ditinjau.');
    }

    // ------------------------------------------------------------ bantu

    /** Alasan tombol "Selesai ditinjau" nonaktif, atau null bila boleh. */
    public static function alasanBelumSelesai(RegulasiDokumen $d): ?string
    {
        if ($d->perlu_dilengkapi) {
            return 'Lengkapi metadata (nomor / tanggal berlaku) terlebih dahulu.';
        }

        return $d->butir()->where('status', 'disetujui')->exists() ? null : 'Belum ada butir yang disetujui.';
    }

    private function rules(): array
    {
        return [
            'judul' => ['required', 'string', 'max:255'],
            'jenis' => ['nullable', 'in:' . implode(',', array_keys(RegulasiDokumen::JENIS))],
            'nomor' => ['nullable', 'required_without:nomor_kmk', 'string', 'max:150'],
            'nomor_kmk' => ['nullable', 'string', 'max:120'],
            'cakupan' => ['nullable', 'array'],
            'cakupan.*' => ['in:' . implode(',', array_keys(RegulasiDokumen::CAKUPAN))],
            'mencabut_teks' => ['nullable', 'string', 'max:255'],
            'mencabut_id' => ['nullable', 'integer', 'exists:regulasi_dokumen,id'],
            'tahun' => ['required', 'integer', 'between:1990,2100'],
            'tanggal_berlaku' => ['required', 'date'],
            'sumber_url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    private function meta(array $v): array
    {
        $jenis = $v['jenis'] ?? 'kmk_pnpk';
        $nomor = trim((string) ($v['nomor'] ?? $v['nomor_kmk'] ?? ''));

        return ['judul' => $v['judul'], 'jenis' => $jenis, 'nomor' => $nomor, 'nomor_kmk' => $jenis === 'kmk_pnpk' ? $nomor : null,
            'cakupan' => array_values($v['cakupan'] ?? []) ?: (RegulasiDokumen::CAKUPAN_BAWAAN[$jenis] ?? ['umum']),
            'mencabut_teks' => $v['mencabut_teks'] ?? null, 'mencabut_id' => $v['mencabut_id'] ?? null,
            'tahun' => (int) $v['tahun'], 'tanggal_berlaku' => $v['tanggal_berlaku'], 'sumber_url' => $v['sumber_url'] ?? null];
    }

    /** Dokumen yang dicabut oleh $d berstatus Dicabut (tetap dipakai untuk tanggal pulang sebelum $d berlaku). */
    private function cabut(RegulasiDokumen $d): void
    {
        $lama = $d->mencabut_id ? RegulasiDokumen::find($d->mencabut_id) : null;
        if ($lama && $lama->status !== 'dicabut') {
            $lama->update(['status' => 'dicabut']);
            Audit::log('pnpk.cabut', $lama, "{$lama->judul} dicabut oleh {$d->judul}", ['lama' => $lama->id, 'pencabut' => $d->id]);
        }
    }

    private function pilihanGanti()
    {
        return RegulasiDokumen::where('status', 'berlaku')->orderBy('judul')->get(['id', 'judul', 'nomor', 'nomor_kmk', 'tahun']);
    }

    private function balik(Request $request, array $json)
    {
        return $request->expectsJson() ? response()->json(['ok' => true] + $json) : back()->with('ok', 'Disimpan.');
    }

    /** Cari di isi PNPK (FULLTEXT di MySQL, LIKE di driver lain) dengan potongan ±200 karakter. */
    private function cariIsi(string $isi)
    {
        $q = DB::table('regulasi_halaman')->join('regulasi_dokumen', 'regulasi_dokumen.id', '=', 'regulasi_halaman.dokumen_id')
            ->select('regulasi_halaman.dokumen_id', 'regulasi_halaman.halaman', 'regulasi_halaman.teks', 'regulasi_dokumen.judul', 'regulasi_dokumen.status');
        if (DB::getDriverName() === 'mysql') {
            $q->whereRaw('MATCH(regulasi_halaman.teks) AGAINST (? IN NATURAL LANGUAGE MODE)', [$isi]);
        } else {
            $q->where('regulasi_halaman.teks', 'like', '%' . $isi . '%');
        }
        $kata = array_values(array_filter(preg_split('/\s+/u', $isi), fn ($k) => mb_strlen($k) >= 3)) ?: [$isi];

        return $q->limit(30)->get()->map(function ($r) use ($kata) {
            $teks = preg_replace('/\s+/u', ' ', $r->teks);
            $pos = 0;
            foreach ($kata as $k) {
                if (($p = mb_stripos($teks, $k)) !== false) {
                    $pos = $p;
                    break;
                }
            }
            $mulai = max(0, $pos - 200);
            $potong = e(($mulai > 0 ? '…' : '') . mb_substr($teks, $mulai, 400 + mb_strlen($kata[0])) . '…');
            $pola = '/(' . implode('|', array_map(fn ($k) => preg_quote(e($k), '/'), $kata)) . ')/iu';
            $r->potongan = preg_replace($pola, '<mark>$1</mark>', $potong);
            unset($r->teks);

            return $r;
        });
    }
}
