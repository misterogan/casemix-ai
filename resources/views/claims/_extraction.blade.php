{{-- Usulan isian data klaim dari dokumen. Variabel: $claim, $extraction, $pending, $canEdit --}}
@php use App\Support\ClaimFields; use App\Enums\ReviewStatus; @endphp
<section class="panel" aria-labelledby="usulan">
    <div class="panel-head">
        <h2 id="usulan" class="panel-title">Usulan isian dari dokumen</h2>
        @if ($extraction?->finished_at)
            <span class="text-xs text-tinta-3">{{ $extraction->finished_at->translatedFormat('d M Y H:i') }} · {{ $extraction->model }}</span>
        @endif
    </div>

    @if ($extraction?->status?->isActive())
        <div class="flex items-center gap-3 px-5 py-3 text-sm text-giok" data-poll="{{ route('claims.extract.status', $claim) }}">
            <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            Membaca SEP, resume medis, dan rincian biaya. Halaman diperbarui otomatis.
        </div>
    @elseif ($extraction?->status === ReviewStatus::Gagal)
        <div class="px-5 py-3 text-sm text-kritis">
            <p class="font-semibold">Pembacaan dokumen gagal.</p>
            <p class="mt-0.5">{{ $extraction->error }}</p>
        </div>
    @elseif ($pending)
        <form method="POST" action="{{ route('claims.extract.apply', [$claim, $extraction]) }}">
            @csrf
            <p class="border-b border-garis px-5 py-3 text-sm text-tinta-2">
                Data berikut dibaca AI dari dokumen dan <strong>berbeda atau belum ada</strong> di berkas.
                Tidak ada yang diterapkan sebelum Anda mencentang dan menekan <em>Terapkan</em>.
                Isian yang sudah ada tidak dicentang otomatis. Periksa terutama yang keyakinannya rendah.
            </p>
            @if ($extraction->isOutdated($claim))
                <p class="border-b border-garis bg-waspada-muda px-5 py-2 text-sm text-waspada">Dokumen berubah setelah usulan ini dibuat. Sebaiknya baca ulang dokumen.</p>
            @endif
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr>
                        <th class="w-10"><input type="checkbox" data-check-all="fields" aria-label="Pilih semua" class="size-4 rounded border-garis-tua accent-giok"></th>
                        <th>Field</th><th>Di berkas sekarang</th><th>Usulan dari dokumen</th><th>Sumber</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($pending as $field => $p)
                        <tr>
                            <td><input type="checkbox" name="fields[]" value="{{ $field }}" @checked($p['kosong'] && $p['keyakinan'] !== 'rendah')
                                       class="size-4 rounded border-garis-tua accent-giok" aria-label="Terapkan {{ $p['label'] }}"></td>
                            <td class="font-medium">{{ $p['label'] }}</td>
                            <td class="whitespace-pre-line text-tinta-3">{{ ClaimFields::display($field, $p['sekarang']) }}</td>
                            <td class="whitespace-pre-line">{{ ClaimFields::display($field, $p['nilai']) }}</td>
                            <td class="text-xs text-tinta-2">
                                {{ $p['dokumen'] ?: '—' }}<br>
                                <span @class(['text-waspada font-medium' => $p['keyakinan'] === 'rendah'])>keyakinan {{ $p['keyakinan'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-garis px-5 py-3">
                <button type="submit" formaction="{{ route('claims.extract', $claim) }}" class="btn-secondary">Baca ulang dokumen</button>
                <button type="submit" class="btn-primary">Terapkan yang dicentang</button>
            </div>
        </form>
    @endif
</section>
