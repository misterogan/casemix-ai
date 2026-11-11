<x-app-layout title="Ringkasan">
    <x-page-header title="Selamat bekerja, {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first() }}"
                   subtitle="{{ now()->translatedFormat('l, d F Y') }}">
        <x-slot:actions>
            @can('claims.import')<a href="{{ route('imports.index') }}" class="btn-secondary">Impor dari SIMRS</a>@endcan
            @can('claims.edit')<a href="{{ route('claims.create') }}" class="btn-primary">Buat berkas</a>@endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Alur status E-Klaim sebagai rangkaian, karena memang berurutan --}}
    <section class="panel mb-6 overflow-hidden" aria-label="Jumlah berkas per status">
        <ol class="grid divide-y divide-garis sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-5 lg:divide-x">
            @foreach ([\App\Enums\ClaimStatus::SepBelumAda, \App\Enums\ClaimStatus::PerluDilengkapi, \App\Enums\ClaimStatus::BelumKoreksi, \App\Enums\ClaimStatus::SudahKoreksi, \App\Enums\ClaimStatus::Disetujui] as $s)
                <li>
                    <a href="{{ route('claims.index', ['status' => $s->value]) }}" class="block px-5 py-4 hover:bg-kertas">
                        <span class="badge {{ $s->tone() }}">{{ $s->label() }}</span>
                        <span class="num mt-2 block text-3xl font-bold text-tinta">{{ $counts[$s->value] }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <div class="space-y-6">
            @foreach ([['Siap disetujui', $readyToApprove, 'Sudah diperiksa AI dan dokumen wajib lengkap.'], ['Perlu ditindaklanjuti', $needsAttention, 'Dokumen kurang, SEP belum ada, atau belum diperiksa.']] as [$heading, $list, $desc])
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h2 class="panel-title">{{ $heading }}</h2>
                            <p class="text-xs text-tinta-3">{{ $desc }}</p>
                        </div>
                        <a href="{{ route('claims.index') }}" class="text-sm font-medium text-giok hover:underline">Lihat semua</a>
                    </div>
                    @if ($list->isEmpty())
                        <p class="px-5 py-6 text-sm text-tinta-3">Tidak ada berkas.</p>
                    @else
                        <ul class="divide-y divide-garis">
                            @foreach ($list as $c)
                                <li>
                                    <a href="{{ route('claims.show', $c) }}" class="flex items-center gap-4 px-5 py-3 hover:bg-kertas">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-semibold">{{ $c->nama_pasien }}</p>
                                            <p class="num text-xs text-tinta-3">RM {{ $c->no_rm }} · SEP {{ $c->no_sep ?: '—' }}</p>
                                        </div>
                                        @if ($r = $c->latestReview)
                                            @if ($r->status === \App\Enums\ReviewStatus::Selesai && ($k = $r->countBy(\App\Enums\Severity::Kritis)))
                                                <span class="badge tone-crit">{{ $k }} kritis</span>
                                            @endif
                                        @endif
                                        <x-status-badge :status="$c->status"/>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>

        <aside class="space-y-6">
            <section class="panel panel-body">
                <h2 class="panel-title">Hari ini</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-tinta-2">Disetujui</dt><dd class="num font-semibold">{{ $approvedToday }}</dd></div>
                    <div class="flex justify-between"><dt class="text-tinta-2">Sedang diperiksa AI</dt><dd class="num font-semibold">{{ $running }}</dd></div>
                </dl>
            </section>
            <section class="panel panel-body">
                <h2 class="panel-title">Penyedia AI aktif</h2>
                @if ($provider)
                    <p class="mt-2 text-sm font-semibold">{{ $provider->name }}</p>
                    <p class="text-xs text-tinta-3">Pemilahan: {{ $provider->model_fast }}<br>Pemeriksaan: {{ $provider->model_standard }}</p>
                    @if ($provider->data_location)<p class="mt-2 text-xs text-tinta-2">Data diproses di: {{ $provider->data_location }}</p>@endif
                @else
                    <p class="mt-2 text-sm text-waspada">Belum ada penyedia AI aktif. Pemeriksaan AI tidak bisa dijalankan.</p>
                    @can('admin.manage')<a href="{{ route('admin.providers.create') }}" class="btn-secondary mt-3">Atur penyedia AI</a>@endcan
                @endif
            </section>
            <section class="rounded-lg border border-manila-tua bg-manila/50 p-4 text-sm leading-relaxed text-tinta-2">
                AI hanya memberi temuan dan saran. Kode ICD-10/ICD-9-CM dan keputusan akhir tetap ditetapkan petugas casemix bersama DPJP.
            </section>
        </aside>
    </div>
</x-app-layout>
