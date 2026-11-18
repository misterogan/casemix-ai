<x-app-layout title="Ringkasan">
    <x-page-header title="Selamat bekerja, {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first() }}"
                   subtitle="{{ now()->translatedFormat('l, d F Y') }}">
        <x-slot:actions>
            @can('claims.import')<a href="{{ route('imports.index') }}" class="btn-secondary">Impor dari SIMRS</a>@endcan
            @can('claims.edit')<a href="{{ route('claims.create') }}" class="btn-primary">Buat berkas</a>@endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Jumlah berkas per status (alur E-Klaim berurutan) --}}
    <div class="grid grid-cols-12 gap-4 md:gap-6">
        <section class="col-span-12 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-5" aria-label="Jumlah berkas per status">
            @foreach ([\App\Enums\ClaimStatus::SepBelumAda, \App\Enums\ClaimStatus::PerluDilengkapi, \App\Enums\ClaimStatus::BelumDiperiksa, \App\Enums\ClaimStatus::SudahDiperiksa, \App\Enums\ClaimStatus::Disetujui] as $s)
                <x-stat-card :label="$s->label()" :value="$counts[$s->value]" :href="route('claims.index', ['status' => $s->value])"
                    :icon="['file', 'alert', 'clock', 'folder-check', 'check'][$loop->index]" :tone="['gray', 'warning', 'info', 'brand', 'success'][$loop->index]"/>
            @endforeach
        </section>

        <div class="col-span-12 grid min-w-0 gap-4 md:gap-6 xl:col-span-8">
            @foreach ([['Siap disetujui', $readyToApprove, 'Sudah diperiksa AI dan dokumen wajib lengkap.'], ['Perlu ditindaklanjuti', $needsAttention, 'Dokumen kurang, SEP belum ada, atau belum diperiksa.']] as [$heading, $list, $desc])
                <x-card :title="$heading" :subtitle="$desc" class="min-w-0">
                    <x-slot:actions><a href="{{ route('claims.index') }}" class="text-sm font-medium text-brand-500 hover:underline">Lihat semua</a></x-slot:actions>
                    @if ($list->isEmpty())
                        <p class="text-sm text-gray-500">Tidak ada berkas.</p>
                    @else
                        <ul class="-my-3 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($list as $c)
                                <li>
                                    <a href="{{ route('claims.show', $c) }}" class="flex items-center gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-full {{ $loop->parent->first ? 'bg-success-50 text-success-600 dark:bg-success-500/10' : 'bg-warning-50 text-warning-600 dark:bg-warning-500/10' }}" aria-hidden="true"><x-icon :name="$loop->parent->first ? 'check' : 'alert'" class="size-4"/></span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $c->nama_pasien }}</p>
                                            <p class="num truncate text-xs text-gray-500">RM {{ $c->no_rm }} · SEP {{ $c->no_sep ?: '—' }}</p>
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
                </x-card>
            @endforeach
        </div>

        <aside class="col-span-12 space-y-4 md:space-y-6 xl:col-span-4">
            <section class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/[.03]">
                <h2 class="panel-title">Hari ini</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-tinta-2">Disetujui</dt><dd class="num font-semibold">{{ $approvedToday }}</dd></div>
                    <div class="flex justify-between"><dt class="text-tinta-2">Sedang diperiksa AI</dt><dd class="num font-semibold">{{ $running }}</dd></div>
                </dl>
            </section>
            <section class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/[.03]">
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
