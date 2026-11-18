{{-- Temuan gabungan semua file, per tingkat. Centang "Sudah dicek" disimpan per kunci stabil (Koordinator). --}}
@php
    $grup = ['wajib' => ['Wajib diperbaiki', 'border-l-red-600', 'text-red-800'], 'perhatian' => ['Perhatian', 'border-l-amber-500', 'text-amber-800'], 'info' => ['Info', 'border-l-gray-400', 'text-gray-600']];
    $bolehCek = auth()->user()->hasRole('koordinator');
    $fileTemuan = $temuan->pluck('dokumen')->filter()->unique()->values();
@endphp
<section id="bagian-temuan" data-bagian="temuan" class="vf-kartu" aria-labelledby="judul-temuan" data-temuan-bagian>
    <h2 id="judul-temuan" class="vf-judul">Temuan ({{ $temuan->count() }})</h2>
    @if ($temuan->isEmpty())
        <p class="mt-2 text-gray-600">Belum ada temuan dari analisa.</p>
    @else
        <p class="mt-2 text-[13px] text-gray-600" data-temuan-progres aria-live="polite"><span data-temuan-dicek>{{ $jumlahDicek }}</span> dari {{ $temuan->count() }} sudah dicek</p>
        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="Temuan sudah dicek"
             aria-valuemin="0" aria-valuemax="{{ $temuan->count() }}" aria-valuenow="{{ $jumlahDicek }}" data-temuan-bar>
            <div class="h-full rounded-full bg-brand-500" style="width: {{ round($jumlahDicek / $temuan->count() * 100) }}%"></div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-4 text-[13px] text-gray-700">
            <label class="inline-flex items-center gap-2"><input type="checkbox" class="size-4" data-sembunyikan-dicek> Sembunyikan yang sudah dicek</label>
            @if ($fileTemuan->count() > 1)
                <label class="inline-flex items-center gap-2">File
                    <select class="field h-8 py-0 text-[13px]" data-filter-file>
                        <option value="">Semua file</option>
                        @foreach ($fileTemuan as $nama)<option value="{{ $nama }}">{{ $nama }}</option>@endforeach
                    </select>
                </label>
            @endif
            <button type="button" class="ml-auto text-[13px] text-gray-600 underline" data-buka-pintasan>Pintasan keyboard (?)</button>
        </div>

        @foreach ($grup as $kode => [$judul, $garis, $warna])
            @php $isi = $temuan->where('kelompok', $kode); @endphp
            @continue($isi->isEmpty())
            <h3 class="mt-5 text-[15px] font-semibold {{ $warna }}" data-kelompok="{{ $kode }}">{{ $judul }} ({{ $isi->count() }})</h3>
            <ul class="mt-2 space-y-3">
                @foreach ($isi as $f)
                    @php
                        $cek = $dicek->has($f['key']);
                        $url = $tautan($f['dokumen'] ?? null, $f['halaman'] ?? null);
                    @endphp
                    <li class="vf-temuan vf-sasaran border-l-4 {{ $garis }}" tabindex="-1" data-temuan="{{ $f['key'] }}" data-dokumen="{{ $f['dokumen'] ?? '' }}" data-dicek="{{ $cek ? '1' : '0' }}">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900">{{ $f['judul'] }}</p>
                                @if (! empty($f['uraian']))<p class="vf-teks mt-1 text-gray-700">{{ $f['uraian'] }}</p>@endif
                                <p class="mt-2 flex flex-wrap items-center gap-2 text-[13px] text-gray-500">
                                    @if (! empty($f['dokumen']))
                                        @if ($url)
                                            <a href="{{ $url }}" data-preview="{{ $f['dokumen'] }}" target="_blank" rel="noopener" class="vf-chip vf-chip-bukti">{{ $f['dokumen'] }}@if (! empty($f['halaman'])) · hal. {{ $f['halaman'] }}@endif</a>
                                        @else
                                            <span class="vf-chip">{{ $f['dokumen'] }}@if (! empty($f['halaman'])) · hal. {{ $f['halaman'] }}@endif</span>
                                        @endif
                                    @endif
                                    <span>{{ $f['kategori'] ?? 'Lainnya' }} · {{ ($f['sumber'] ?? '') === 'aturan' ? 'Aturan E-Klaim' : 'AI' }}</span>
                                </p>
                            </div>
                            <label class="inline-flex shrink-0 items-center gap-2 text-[13px] text-gray-700">
                                <input type="checkbox" class="size-4" data-cek="{{ $f['key'] }}" @checked($cek) @disabled(! $bolehCek)
                                       aria-label="Sudah dicek: {{ $f['judul'] }}">
                                <span aria-hidden="true">Sudah dicek</span>
                            </label>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endforeach
    @endif
</section>
