@php
    $usd = fn ($v) => 'US$' . number_format((float) $v, 2);
    $bar = $status['budget'] ? min(100, (int) round(($status['spent'] / $status['budget']) * 100)) : 0;
    $tone = ['habis' => 'bg-kritis', 'peringatan' => 'bg-waspada', 'aman' => 'bg-sesuai', 'tanpa_batas' => 'bg-tinta-3'][$status['state']];
    $label = ['habis' => 'Habis: proses AI baru ditahan', 'peringatan' => 'Mendekati batas', 'aman' => 'Aman', 'tanpa_batas' => 'Tanpa batas'][$status['state']];
@endphp
<x-app-layout title="Anggaran AI">
    <x-page-header title="Anggaran AI" subtitle="Lapis kedua di dalam aplikasi. Angka di sini perkiraan dari token yang tercatat; tagihan resmi ada di dasbor penyedia AI (Claude Console). Tetap pasang batas pengeluaran di Console sebagai lapis pertama."/>

    <div class="grid gap-6 lg:grid-cols-[1fr_380px]">
        <div class="min-w-0 space-y-6">
            <section class="panel">
                <div class="panel-head">
                    <h2 class="panel-title">Bulan ini ({{ now()->translatedFormat('F Y') }})</h2>
                    <span class="text-sm font-medium">{{ $label }}</span>
                </div>
                <div class="space-y-3 px-5 py-4">
                    <p class="text-2xl font-semibold num">{{ $usd($status['spent']) }}
                        @if ($status['budget'])<span class="text-base font-normal text-tinta-3"> dari {{ $usd($status['budget']) }} ({{ $status['pct'] }}%)</span>@endif
                    </p>
                    @if ($status['budget'])
                        <div class="h-2.5 overflow-hidden rounded-full bg-garis" role="progressbar" aria-valuenow="{{ $bar }}" aria-valuemin="0" aria-valuemax="100" aria-label="Pemakaian anggaran">
                            <div class="h-full {{ $tone }}" style="width: {{ $bar }}%"></div>
                        </div>
                    @endif
                    <p class="text-sm text-tinta-2">
                        Rata-rata {{ $usd($average) }} per proses.
                        @if ($status['reserved'] > 0) Sedang diantre/berjalan: perkiraan {{ $usd($status['reserved']) }}. @endif
                        Proses baru ditahan bila pemakaian ditambah antrean mencapai anggaran.
                    </p>
                    @if ($status['unpriced'])
                        <p class="rounded-md bg-waspada-muda px-3 py-2 text-sm text-waspada">
                            {{ $status['unpriced'] }} proses bulan ini tidak terhitung biayanya karena harga modelnya tidak diketahui.
                            Isi harga di <a href="{{ route('admin.providers.index') }}" class="underline">Penyedia AI</a> agar batas anggaran akurat.
                        </p>
                    @endif
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">Per fitur, bulan ini</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Fitur</th><th class="text-right">Proses</th><th class="text-right">Token</th><th class="text-right">Perkiraan biaya</th></tr></thead>
                        <tbody>
                        @forelse ($report['per_fitur'] as $fitur => $r)
                            <tr><td>{{ $fitur }}</td><td class="num text-right">{{ $r['jumlah'] }}</td><td class="num text-right">{{ number_format($r['token']) }}</td><td class="num text-right">{{ $usd($r['biaya']) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-tinta-3">Belum ada pemakaian bulan ini.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">Per petugas, bulan ini</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Petugas</th><th class="text-right">Proses</th><th class="text-right">Perkiraan biaya</th></tr></thead>
                        <tbody>
                        @forelse ($report['per_petugas'] as $r)
                            <tr><td>{{ $r['nama'] }}</td><td class="num text-right">{{ $r['jumlah'] }}</td><td class="num text-right">{{ $usd($r['biaya']) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-tinta-3">Belum ada pemakaian bulan ini.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">6 bulan terakhir</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Bulan</th><th class="text-right">Proses</th><th class="text-right">Perkiraan biaya</th></tr></thead>
                        <tbody>
                        @forelse ($report['per_bulan'] as $bulan => $r)
                            <tr><td class="num">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $bulan)->translatedFormat('F Y') }}</td><td class="num text-right">{{ $r['jumlah'] }}</td><td class="num text-right">{{ $usd($r['biaya']) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-tinta-3">Belum ada data.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside>
            <form method="POST" action="{{ route('admin.budget.update') }}" class="panel">
                @csrf @method('PUT')
                <div class="panel-head"><h2 class="panel-title">Pengaturan</h2></div>
                <div class="space-y-4 px-5 py-4">
                    <x-field name="budget" label="Anggaran per bulan (USD)" hint="Kosongkan atau 0 untuk tanpa batas. Samakan atau buat sedikit di bawah batas di Claude Console.">
                        <input id="budget" type="number" step="0.01" min="0" name="budget" value="{{ old('budget', $status['budget']) }}" class="field num" placeholder="200">
                    </x-field>
                    <x-field name="warn_pct" label="Peringatan pada (%)" required hint="Admin dan Koordinator melihat peringatan setelah pemakaian mencapai persentase ini.">
                        <input id="warn_pct" type="number" min="10" max="99" name="warn_pct" value="{{ old('warn_pct', $status['warn_pct']) }}" required class="field num">
                    </x-field>
                    <p class="text-xs text-tinta-3">Saat anggaran habis: pemeriksaan AI dan isi otomatis ditahan dengan pesan yang jelas, aturan otomatis tetap berjalan, dan semuanya terbuka lagi pada tanggal 1 bulan berikutnya.</p>
                </div>
                <div class="border-t border-garis px-5 py-3 text-right"><button type="submit" class="btn-primary">Simpan</button></div>
            </form>
        </aside>
    </div>
</x-app-layout>
