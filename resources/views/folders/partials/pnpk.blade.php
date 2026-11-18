{{-- Acuan PNPK (Koordinator): hanya butir disetujui dari PNPK yang berlaku pada tanggal pulang, bila diagnosis cocok prefix ICD disetujui. --}}
@php $acuanPnpk = app(\App\Services\Pnpk\PnpkAcuan::class)->forFolder($folder); @endphp
@if ($acuanPnpk->isNotEmpty())
    <section class="panel shrink-0" data-acuan-pnpk>
        <div class="flex items-center justify-between border-b border-garis px-5 py-3">
            <h2 class="font-semibold text-arang">Acuan PNPK</h2>
            <a href="{{ route('acuan.pnpk.index') }}" class="text-xs text-url">Kelola PNPK</a>
        </div>
        @foreach ($acuanPnpk as $x)
            @php $d = $x['dokumen']; @endphp
            <details class="border-b border-garis px-5 py-3 last:border-0" @if ($loop->first) open @endif>
                <summary class="cursor-pointer text-sm font-medium text-arang">{{ $d->judul }} <span class="num text-xs font-normal text-tinta-3">· {{ $d->nomor_kmk }} · {{ $d->tahun }}</span>
                    @if ($d->butir->contains('diperiksa_manusia', false))<x-badge variant="warning" data-belum-diperiksa>Belum diperiksa koder/dokter</x-badge>@endif</summary>
                @foreach (\App\Models\PnpkButir::JENIS as $jenis => $label)
                    @continue(! $x['butir']->has($jenis))
                    <p class="mt-3 text-xs font-semibold uppercase text-tinta-3">{{ $label }}</p>
                    <ul class="mt-1 space-y-2 text-sm">
                        @foreach ($x['butir'][$jenis] as $b)
                            <li data-pnpk-butir="{{ $b->id }}">
                                <p class="text-arang">{{ $b->ringkasan }}</p>
                                <blockquote class="border-l-4 border-garis pl-3 text-xs italic text-tinta-3">“{{ $b->kutipan }}”
                                    <a class="not-italic text-url" href="{{ route('acuan.pnpk.pdf', $d) }}#page={{ $b->halaman }}" target="_blank" rel="noopener">hal. {{ $b->halaman }}</a></blockquote>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </details>
        @endforeach
    </section>
@endif
