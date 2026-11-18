<?php

namespace App\Services\Pnpk;

use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\PnpkIcd;
use App\Support\Audit;

/** Verifikasi ulang tanpa AI: kutipan butir yang ditolak otomatis, ICD AI yang ditolak, dan ekstraksi ICD dari teks. */
class PnpkVerifikasiUlang
{
    public function __construct(private PnpkVerifier $verifier, private PnpkIcdTeks $icdTeks) {}

    /** @return array{butir_sebelum: int, butir_sesudah: int, icd_sebelum: int, icd_sesudah: int} */
    public function jalankan(PnpkDokumen $d): array
    {
        $hitungButir = fn () => PnpkButir::where('dokumen_id', $d->id)->where('cocok_kutipan', true)->count();
        $hitungIcd = fn () => PnpkIcd::where('dokumen_id', $d->id)->where('status', 'usulan')->count();
        $hasil = ['butir_sebelum' => $hitungButir(), 'icd_sebelum' => $hitungIcd()];
        $pages = $d->halaman()->pluck('teks', 'halaman')->all();

        // Butir: hanya usulan, atau ditolak OTOMATIS karena kutipan (bukan ditolak manual oleh peninjau).
        PnpkButir::where('dokumen_id', $d->id)->whereNull('ditinjau_oleh')
            ->where(fn ($q) => $q->where('status', 'usulan')
                ->orWhere(fn ($q) => $q->where('status', 'ditolak')->where('alasan_tolak', PnpkVerifier::TOLAK_KUTIPAN)))
            ->each(function (PnpkButir $b) use ($pages) {
                $hal = $this->verifier->cariHalaman($b->kutipan, (int) $b->halaman, $pages);
                if ($hal !== null) {
                    $b->update(['cocok_kutipan' => true, 'halaman_pdf' => $hal, 'status' => 'usulan', 'alasan_tolak' => null]);
                } elseif (! $b->cocok_kutipan) {
                    $b->update(['status' => 'ditolak', 'alasan_tolak' => PnpkVerifier::TOLAK_KUTIPAN]);
                }
            });

        // ICD AI yang ditolak otomatis: normalisasi & validasi ulang.
        PnpkIcd::where('dokumen_id', $d->id)->where('sumber', 'ai')->where('status', 'ditolak')
            ->where('alasan_tolak', PnpkVerifier::TOLAK_ICD)->whereNull('ditinjau_oleh')
            ->each(function (PnpkIcd $i) use ($d) {
                if (! $this->verifier->icdValid($i->kode)) {
                    return;
                }
                $kode = PnpkIcd::normalize($i->kode);
                $bentrok = PnpkIcd::where('dokumen_id', $d->id)->where('kode', $kode)->where('id', '!=', $i->id)->exists();
                $i->update(['status' => 'usulan', 'alasan_tolak' => null] + ($bentrok ? [] : ['kode' => $kode]));
            });
        $this->icdTeks->simpan($d);

        $hasil += ['butir_sesudah' => $hitungButir(), 'icd_sesudah' => $hitungIcd()];
        Audit::system('pnpk.verifikasi_ulang', $d, "Verifikasi ulang PNPK {$d->judul} (tanpa AI)", null, $hasil);

        return $hasil;
    }
}
