<x-app-layout title="Klaim BPJS {{ $noSep }}">
@php
    $rp = fn ($n) => $n === null ? '—' : 'Rp ' . number_format((float) $n, 0, ',', '.');
    $st = fn ($k) => $k === null ? '—' : (\App\Models\VclaimKlaim::STATUS[$k] ?? $k);
    $isi = function ($v) { return $v instanceof \DateTimeInterface ? $v->format('d/m/Y' . ($v->format('H:i:s') === '00:00:00' ? '' : ' H:i')) : ($v ?? '—'); };
@endphp
<div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('bpjs.index', ['tab' => 'klaim']) }}" class="text-sm text-biru-aktif">&larr; Daftar klaim</a>
            <h1 class="mt-1 text-xl font-bold text-ink">{{ $klaim?->nama ?? $kunjungan?->nama ?? 'Pasien' }}</h1>
            <p class="tabular text-sm text-ink-muted">SEP {{ $noSep }} @if ($klaim?->no_mr) · RM {{ $klaim->no_mr }} @endif</p>
        </div>
        <div class="flex items-center gap-2">
            @include('folders.partials.bpjs-chip', ['kode' => $klaim?->status_kode ?? 0])
            @if ($folder)<a href="{{ route('folders.show', $folder->id) }}" class="btn-secondary" data-folder-link>Buka folder CERMAT</a>@endif
        </div>
    </header>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-panel data-panel-klaim>
            <div class="border-b border-line px-4 py-3"><h2 class="font-semibold text-ink">Data klaim</h2></div>
            @if ($klaim)
                <table class="w-full text-sm"><tbody class="divide-y divide-gray-100">
                    @foreach (collect($klaim->getAttributes())->except(['id', 'created_at', 'updated_at']) as $k => $v)
                        @php $v = $klaim->$k; @endphp
                        <tr><th scope="row" class="px-4 py-1.5 text-left font-normal text-ink-muted">{{ $k }}</th>
                            <td @class(['px-4 py-1.5 text-arang', 'tabular text-right' => str_starts_with($k, 'by_')])>{{ str_starts_with($k, 'by_') ? $rp($v) : ($k === 'status_kode' ? $v . ' · ' . $st($v) : $isi($v)) }}</td></tr>
                    @endforeach
                </tbody></table>
            @else
                <x-empty-state title="Belum terlihat di data klaim"/>
            @endif
        </x-panel>

        <div class="space-y-4">
            <x-panel data-panel-kunjungan>
                <div class="border-b border-line px-4 py-3"><h2 class="font-semibold text-ink">Data kunjungan (SEP)</h2></div>
                @if ($kunjungan)
                    <table class="w-full text-sm"><tbody class="divide-y divide-gray-100">
                        @foreach (collect($kunjungan->getAttributes())->except(['id', 'created_at', 'updated_at']) as $k => $v)
                            <tr><th scope="row" class="px-4 py-1.5 text-left font-normal text-ink-muted">{{ $k }}</th><td class="px-4 py-1.5 text-arang">{{ $isi($kunjungan->$k) }}</td></tr>
                        @endforeach
                    </tbody></table>
                @else
                    <p class="px-4 py-3 text-sm text-ink-muted">Data kunjungan tidak tersedia.</p>
                @endif
            </x-panel>

            <x-panel data-panel-status-history>
                <div class="border-b border-line px-4 py-3"><h2 class="font-semibold text-ink">Riwayat perubahan status</h2></div>
                @if ($klaim && $klaim->statusHistory->isNotEmpty())
                    <ol class="divide-y divide-gray-100 text-sm">
                        @foreach ($klaim->statusHistory as $h)
                            <li class="flex justify-between gap-3 px-4 py-2" data-status-history>
                                <span>{{ $h->status_lama === null ? 'Pertama terlihat' : $st($h->status_lama) }} &rarr; <span class="font-medium">{{ $st($h->status_baru) }}</span></span>
                                <span class="tabular text-ink-muted">{{ \Illuminate\Support\Carbon::parse($h->terlihat_at)->format('d/m/Y H:i') }}</span>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="px-4 py-3 text-sm text-ink-muted">Belum ada riwayat status.</p>
                @endif
            </x-panel>
        </div>
    </div>

    <x-panel data-panel-riwayat>
        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
            <h2 class="font-semibold text-ink">Riwayat pelayanan peserta (90 hari)</h2>
            @if ($enabled)
                <form method="POST" action="{{ route('bpjs.klaim.riwayat', $noSep) }}">@csrf
                    <button type="submit" class="btn-secondary text-sm" @disabled(! $noKartu)>Ambil riwayat pelayanan</button>
                </form>
            @endif
        </div>
        <div class="px-4 py-3 text-sm">
            @if (session('status'))<p class="mb-2 text-xs text-biru-aktif" role="status">{{ session('status') }}</p>@endif
            @if (! $noKartu)
                <p class="text-xs text-ink-muted">Nomor kartu peserta belum diketahui.</p>
            @elseif ($riwayat->isEmpty())
                <p class="text-xs text-ink-muted">Belum ada riwayat tersimpan. Klik Ambil riwayat pelayanan.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-left text-ink-muted"><tr><th class="py-1 pr-2 font-medium">Tgl SEP</th><th class="py-1 pr-2 font-medium">Tgl pulang</th><th class="py-1 pr-2 font-medium">Jenis</th><th class="py-1 pr-2 font-medium">PPK</th><th class="py-1 font-medium">Diagnosa</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($riwayat as $r)
                                <tr @class(['bg-waspada-muda' => $r->readmisi]) @if ($r->readmisi) data-readmisi @endif>
                                    <td class="py-1 pr-2 tabular-nums">{{ $r->tgl_sep?->format('d/m/Y') }}</td>
                                    <td class="py-1 pr-2 tabular-nums">{{ $r->tgl_plg_sep?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-1 pr-2">{{ $r->jns_pelayanan }}</td>
                                    <td class="py-1 pr-2">{{ $r->ppk_pelayanan }}</td>
                                    <td class="py-1">{{ $r->diagnosa }}
                                        @if ($r->readmisi)<span class="chip chip-waspada mt-1">Kemungkinan readmisi</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </x-panel>
</div>
</x-app-layout>
