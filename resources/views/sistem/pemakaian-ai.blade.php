@php
    $usd = fn ($v) => '$' . number_format((float) $v, 2);
    $maxJenis = max(0.000001, (float) $perJenis->max('biaya'));
    $maxHari = max(0.000001, (float) $perModelHari->max('biaya'));
    $label = fn ($j) => \App\Models\AiUsage::JENIS[$j] ?? $j;
@endphp
<x-app-layout title="Pemakaian AI">
    <x-page-header title="Pemakaian AI" subtitle="Token dan perkiraan biaya per panggilan AI bulan ini. Hanya jenis tugas dan nomor rujukan; tanpa data pasien."/>

    <div class="space-y-6">
        <section class="panel">
            <div class="space-y-3 px-5 py-4">
                <p class="text-2xl font-semibold num" data-kartu-pemakaian>Bulan ini {{ $usd($total) }} dari {{ $anggaran > 0 ? $usd($anggaran) : 'tanpa batas' }}</p>
                @if ($persen !== null)
                    <div class="h-2.5 overflow-hidden rounded-full bg-garis" role="progressbar" aria-valuenow="{{ min(100, $persen) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Pemakaian anggaran">
                        <div @class(['h-full', 'bg-kritis' => $persen >= 100, 'bg-waspada' => $persen >= 80 && $persen < 100, 'bg-sesuai' => $persen < 80]) style="width: {{ min(100, $persen) }}%"></div>
                    </div>
                    <p class="text-sm text-tinta-2">{{ $persen }}% dari anggaran.</p>
                @endif
                <p class="text-sm text-tinta-2">Rata-rata token per analisa: {{ $rataAnalisa !== null ? number_format($rataAnalisa) : '—' }}</p>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2 class="panel-title">Per jenis tugas</h2></div>
            <div class="space-y-3 px-5 py-4">
                @forelse ($perJenis as $r)
                    <div>
                        <div class="flex justify-between gap-3 text-sm"><span>{{ $label($r->jenis) }}</span>
                            <span class="num">{{ $usd($r->biaya) }} · {{ number_format($r->token) }} token · {{ $r->jumlah }}×</span></div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-garis"><div class="h-full bg-tinta-3" style="width: {{ round($r->biaya / $maxJenis * 100) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-tinta-3">Belum ada pemakaian bulan ini.</p>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2 class="panel-title">Per model per hari</h2></div>
            <div class="space-y-3 px-5 py-4">
                @forelse ($perModelHari as $r)
                    <div>
                        <div class="flex justify-between gap-3 text-sm"><span>{{ $r->hari }} · {{ $r->model ?: '—' }}</span>
                            <span class="num">{{ $usd($r->biaya) }} · {{ number_format($r->token) }} token</span></div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-garis"><div class="h-full bg-tinta-3" style="width: {{ round($r->biaya / $maxHari * 100) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-tinta-3">Belum ada pemakaian bulan ini.</p>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2 class="panel-title">10 pemakaian terbesar</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-tinta-3"><th class="px-5 py-2">Jenis</th><th class="px-3 py-2">Ref</th><th class="px-3 py-2">Token</th><th class="px-3 py-2">Biaya</th><th class="px-5 py-2">Waktu</th></tr></thead>
                    <tbody>
                        @forelse ($terbesar as $r)
                            <tr class="border-t border-garis">
                                <td class="px-5 py-2">{{ $label($r->jenis) }}</td>
                                <td class="px-3 py-2 num">{{ $r->ref_id ?? '—' }}</td>
                                <td class="px-3 py-2 num">{{ number_format($r->input_tokens + $r->output_tokens + $r->cache_read_tokens) }}</td>
                                <td class="px-3 py-2 num">${{ number_format($r->biaya_usd, 4) }}</td>
                                <td class="px-5 py-2">{{ $r->created_at?->format('d-m-Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-3 text-tinta-3">Belum ada pemakaian bulan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
