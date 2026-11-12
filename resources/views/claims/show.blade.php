@php
    use App\Enums\DocumentType;
    use App\Enums\ReviewStatus;
    use App\Enums\Severity;
    use App\Support\Eklaim;
    $canEdit = auth()->user()->can('update', $claim);
    $canReview = auth()->user()->can('review', $claim);
    $findings = $completed?->allFindings() ?? collect();
    $justApproved = session('ok') && $claim->isLocked() && $claim->approved_at?->gt(now()->subMinute());
@endphp
<x-app-layout :title="$claim->nama_pasien">
    <x-page-header :title="$claim->nama_pasien" :back="route('claims.index')">
        <x-slot:meta>
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-tinta-2">
                <span class="num">RM <strong class="text-tinta">{{ $claim->no_rm }}</strong></span>
                <span class="num">SEP <strong class="text-tinta">{{ $claim->no_sep ?: 'belum ada' }}</strong></span>
                <span>{{ Eklaim::JENIS_RAWAT[$claim->jenis_rawat] ?? $claim->jenis_rawat }}</span>
                <x-status-badge :status="$claim->status"/>
            </div>
        </x-slot:meta>
        <x-slot:actions>
            @can('reports.download')
                <a href="{{ route('claims.report', $claim) }}" target="_blank" class="btn-secondary">Cetak laporan</a>
            @endcan
            @if ($canEdit)
                <a href="{{ route('claims.edit', $claim) }}" class="btn-secondary">Ubah data</a>
            @endif
            @if ($canReview)
                <form method="POST" action="{{ route('claims.review', $claim) }}">
                    @csrf
                    <button type="submit" class="btn-primary" @disabled($review?->status?->isActive())>
                        {{ $completed ? 'Periksa ulang dengan AI' : 'Periksa dengan AI' }}
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        {{-- ============ KOLOM UTAMA ============ --}}
        <div class="min-w-0 space-y-6">

            {{-- Hasil pemeriksaan --}}
            <section class="panel" aria-labelledby="hasil">
                <div class="panel-head">
                    <h2 id="hasil" class="panel-title">Hasil pemeriksaan</h2>
                    @if ($completed)
                        <span class="text-xs text-tinta-3">
                            {{ $completed->finished_at?->translatedFormat('d M Y H:i') }} · {{ $completed->model }}
                            @if ($completed->requester) · oleh {{ $completed->requester->name }} @endif
                        </span>
                    @endif
                </div>

                @if ($review && $review->status->isActive())
                    <div class="flex items-center gap-3 border-b border-garis bg-giok-muda px-5 py-3 text-sm text-giok" data-poll="{{ route('claims.review.status', $claim) }}">
                        <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        {{ $review->status->label() }}. Halaman diperbarui otomatis.
                    </div>
                @elseif ($review && $review->status === ReviewStatus::Gagal)
                    <div class="border-b border-garis bg-kritis-muda px-5 py-3 text-sm text-kritis">
                        <p class="font-semibold">Pemeriksaan AI terakhir gagal.</p>
                        <p class="mt-0.5">{{ $review->error }}</p>
                        @if ($review->rule_findings)<p class="mt-1 text-tinta-2">Temuan aturan otomatis tetap tersimpan di riwayat pemeriksaan.</p>@endif
                    </div>
                @endif

                @if ($completed && $stale)
                    <div class="border-b border-garis bg-waspada-muda px-5 py-3 text-sm text-waspada">
                        Hasil ini usang karena data atau dokumen berubah setelah pemeriksaan. Jalankan pemeriksaan ulang sebelum disetujui.
                    </div>
                @endif

                @if (! $completed)
                    <div class="border-b border-garis bg-kertas px-5 py-3 text-sm text-tinta-2">
                        Belum diperiksa AI. Di bawah ini hasil <strong class="text-tinta">aturan E-Klaim</strong> (tanpa AI), dihitung ulang setiap halaman dibuka.
                    </div>
                    @if (empty($liveRules))
                        <p class="flex items-center gap-2 px-5 py-4 text-sm text-sesuai">
                            <span class="badge tone-ok">Sesuai</span> Tidak ada temuan dari aturan E-Klaim.
                        </p>
                    @else
                        <div class="flex flex-wrap gap-2 px-5 pt-3 text-sm">
                            @foreach (\App\Enums\Severity::cases() as $s)
                                @php $n = collect($liveRules)->where('tingkat', $s->value)->count(); @endphp
                                @if ($n)<span class="badge {{ $s->tone() }}">{{ $n }} {{ mb_strtolower($s->label()) }}</span>@endif
                            @endforeach
                        </div>
                        <ul class="mt-3 divide-y divide-garis border-t border-garis">
                            @foreach ($liveRules as $f)
                                @include('claims._finding', ['f' => $f + ['sumber' => 'aturan']])
                            @endforeach
                        </ul>
                    @endif
                @else
                    <div @class(['panel-body', 'opacity-60' => $stale])>
                        @if ($completed->summary)
                            <p class="max-w-[72ch] text-[15px] leading-relaxed">{{ $completed->summary }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2 text-sm">
                            @foreach (Severity::cases() as $s)
                                <span class="badge {{ $s->tone() }}">{{ $completed->countBy($s) }} {{ mb_strtolower($s->label()) }}</span>
                            @endforeach
                        </div>
                    </div>

                    <ul @class(['divide-y divide-garis border-t border-garis', 'opacity-60' => $stale])>
                        @foreach ($findings as $f)
                            @include('claims._finding', ['f' => $f])
                        @endforeach
                    </ul>

                    @if (! empty($completed->suggestions))
                        <div class="border-t border-garis bg-manila/35 px-5 py-4">
                            <h3 class="font-semibold">Kondisi yang belum tertulis sebagai diagnosis</h3>
                            <p class="mt-0.5 text-sm text-tinta-2">Konfirmasi ke DPJP. Bukan diagnosis dan bukan kode ICD.</p>
                            <ul class="mt-3 space-y-3">
                                @foreach ($completed->suggestions as $s)
                                    <li class="rounded-md border border-manila-tua bg-white px-4 py-3">
                                        <p class="font-semibold">{{ $s['kondisi'] }}</p>
                                        <p class="mt-1 text-sm text-tinta-2"><span class="font-medium text-tinta">Bukti:</span> {{ $s['bukti'] }}@if($s['dokumen']) ({{ $s['dokumen'] }})@endif</p>
                                        @if ($s['catatan'])<p class="mt-1 text-sm text-tinta-2">{{ $s['catatan'] }}</p>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </section>

            {{-- Dokumen --}}
            <section aria-labelledby="dokumen">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <h2 id="dokumen" class="text-lg font-bold">Dokumen</h2>
                    @if ($canEdit)
                        <a href="{{ route('claims.history', $claim) }}" class="text-sm font-medium text-giok hover:underline">Ambil dari riwayat pasien di FTP</a>
                    @endif
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($documentTypes as $type)
                        @php $docs = $claim->documentsOfType($type); $missing = $type->required() && $docs->isEmpty(); @endphp
                        <div>
                            <div class="flex items-end">
                                <span @class(['folder-tab', 'bg-kritis-muda! border-kritis/30! text-kritis!' => $missing])>
                                    {{ $type->label() }}
                                </span>
                                <span class="ml-2 pb-1 text-xs text-tinta-3">{{ $type->required() ? 'wajib' : 'opsional' }}</span>
                            </div>
                            <div @class(['panel rounded-tl-none', 'border-kritis/30!' => $missing])>
                                @if ($docs->isEmpty())
                                    <p class="px-4 py-3 text-sm text-tinta-3">{{ $missing ? 'Belum diunggah.' : 'Tidak ada.' }}</p>
                                @else
                                    <ul class="divide-y divide-garis">
                                        @foreach ($docs as $d)
                                            <li class="flex items-center gap-3 px-4 py-2.5">
                                                <span class="grid size-8 shrink-0 place-items-center rounded bg-kertas text-[10px] font-bold text-tinta-2">{{ $d->isPdf() ? 'PDF' : 'IMG' }}</span>
                                                <div class="min-w-0 flex-1">
                                                    <a href="{{ route('claims.documents.show', [$claim, $d]) }}" target="_blank" class="block truncate text-sm font-medium hover:text-giok">{{ $d->original_name }}</a>
                                                    <p class="text-xs text-tinta-3">
                                                        {{ $d->humanSize() }}@if($d->page_count) · {{ $d->page_count }} hlm @endif
                                                        @if ($d->has_text_layer === false) · scan @endif
                                                        · {{ ['unggah' => 'diunggah', 'ftp' => 'dari FTP', 'riwayat' => 'riwayat pasien'][$d->source] ?? $d->source }}
                                                    </p>
                                                </div>
                                                @if ($canEdit)
                                                    <details class="relative">
                                                        <summary class="btn-ghost cursor-pointer list-none px-2 py-1" aria-label="Aksi dokumen">⋯</summary>
                                                        <div class="absolute right-0 z-10 mt-1 w-56 rounded-md border border-garis bg-white p-2 shadow-lg">
                                                            <form method="POST" action="{{ route('claims.documents.update', [$claim, $d]) }}" class="flex gap-1">
                                                                @csrf @method('PATCH')
                                                                <select name="type" class="field py-1 text-xs" aria-label="Ganti jenis">
                                                                    @foreach ($documentTypes as $t)<option value="{{ $t->value }}" @selected($t === $d->type)>{{ $t->label() }}</option>@endforeach
                                                                </select>
                                                                <button class="btn-secondary px-2 py-1 text-xs">Ganti</button>
                                                            </form>
                                                            <form method="POST" action="{{ route('claims.documents.destroy', [$claim, $d]) }}" class="mt-2" data-confirm="Hapus {{ $d->original_name }}?">
                                                                @csrf @method('DELETE')
                                                                <button class="w-full rounded px-2 py-1.5 text-left text-xs font-medium text-kritis hover:bg-kritis-muda">Hapus dokumen</button>
                                                            </form>
                                                        </div>
                                                    </details>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('claims.documents.store', $claim) }}" enctype="multipart/form-data"
                                          class="flex items-center gap-2 border-t border-dashed border-garis px-4 py-2.5">
                                        @csrf
                                        <input type="hidden" name="type" value="{{ $type->value }}">
                                        <label class="min-w-0 flex-1">
                                            <span class="sr-only">Pilih file {{ $type->label() }}</span>
                                            <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" required
                                                   class="block w-full text-xs text-tinta-2 file:mr-2 file:rounded file:border-0 file:bg-kertas file:px-2.5 file:py-1.5 file:text-xs file:font-semibold file:text-tinta hover:file:bg-garis">
                                        </label>
                                        <button class="btn-secondary px-2.5 py-1 text-xs">Unggah</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Data klaim --}}
            <section class="panel">
                <div class="panel-head">
                    <h2 class="panel-title">Data klaim</h2>
                    @if ($canEdit)<a href="{{ route('claims.edit', $claim) }}" class="text-sm font-medium text-giok hover:underline">Ubah</a>@endif
                </div>
                <dl class="grid gap-x-6 gap-y-3 px-5 py-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        'Tanggal lahir' => $claim->tanggal_lahir?->format('d/m/Y') . ($claim->jenis_kelamin ? ' · ' . ($claim->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : ''),
                        'No. peserta' => $claim->no_peserta,
                        'DPJP' => $claim->dpjp,
                        'Masuk – keluar' => $claim->tanggal_masuk?->format('d/m/Y') . ' – ' . ($claim->tanggal_keluar?->format('d/m/Y') ?? '…'),
                        'LOS' => $claim->los() !== null ? $claim->los() . ' hari' : null,
                        'Cara masuk / pulang' => (Eklaim::CARA_MASUK[$claim->cara_masuk] ?? '—') . ' / ' . (Eklaim::CARA_PULANG[$claim->cara_pulang] ?? '—'),
                        'Hak kelas / kelas rawat' => $claim->jenis_rawat === 'inap' ? (Eklaim::KELAS[$claim->kelas_hak] ?? '—') . ' / ' . (Eklaim::KELAS_RAWAT[$claim->kelas_rawat] ?? '—') . ($claim->naik_kelas ? ' (naik kelas)' : '') : null,
                        'Rawat intensif' => $claim->rawat_intensif_hari ? $claim->rawat_intensif_hari . ' hari' . ($claim->ventilator_jam ? ', ventilator ' . $claim->ventilator_jam . ' jam' : '') : null,
                        'Berat lahir' => $claim->berat_lahir ? $claim->berat_lahir . ' gram' : null,
                    ] as $k => $val)
                        @if (filled(trim((string) $val, ' ·–/')))
                            <div><dt class="text-xs text-tinta-3">{{ $k }}</dt><dd class="num mt-0.5">{{ $val }}</dd></div>
                        @endif
                    @endforeach
                </dl>
                <dl class="grid gap-4 border-t border-garis px-5 py-4 text-sm md:grid-cols-3">
                    <div><dt class="text-xs text-tinta-3">Diagnosis utama</dt><dd class="mt-0.5 whitespace-pre-line">{{ $claim->diagnosis_utama ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-tinta-3">Diagnosis sekunder</dt><dd class="mt-0.5 whitespace-pre-line">{{ $claim->diagnosis_sekunder ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-tinta-3">Tindakan</dt><dd class="mt-0.5 whitespace-pre-line">{{ $claim->tindakan ?: '—' }}</dd></div>
                </dl>
                @if ($claim->tarifTotal() > 0)
                    <details class="border-t border-garis">
                        <summary class="flex cursor-pointer items-center justify-between px-5 py-3 text-sm">
                            <span class="font-medium">Komponen tarif</span>
                            <span class="num font-semibold">{{ Eklaim::rupiah($claim->tarifTotal()) }}</span>
                        </summary>
                        <dl class="grid gap-x-6 gap-y-1.5 px-5 pb-4 text-sm sm:grid-cols-2">
                            @foreach (Eklaim::TARIF as $k => $l)
                                @if (($claim->tarif[$k] ?? 0) > 0)
                                    <div class="flex justify-between gap-3"><dt class="text-tinta-2">{{ $l }}</dt><dd class="num">{{ Eklaim::rupiah($claim->tarif[$k]) }}</dd></div>
                                @endif
                            @endforeach
                        </dl>
                    </details>
                @endif
            </section>
        </div>

        {{-- ============ KOLOM PERSETUJUAN ============ --}}
        <aside class="space-y-6 xl:sticky xl:top-6 xl:self-start">
            @if ($claim->isLocked())
                <section class="panel overflow-hidden">
                    <div class="grid place-items-center bg-[#F4F2FB] px-5 py-8">
                        <x-approval-stamp :claim="$claim" :animate="$justApproved"/>
                    </div>
                    <div class="panel-body text-sm text-tinta-2">
                        Berkas terkunci. Lanjutkan entri, Proses, dan Final Klaim di E-Klaim.
                    </div>
                    @can('reopen', $claim)
                        <form method="POST" action="{{ route('claims.reopen', $claim) }}" class="border-t border-garis px-5 py-4"
                              data-confirm="Buka kembali berkas ini? Pemeriksaan AI dan daftar cek harus diulang.">
                            @csrf
                            <label for="alasan" class="label">Alasan membuka kembali</label>
                            <textarea id="alasan" name="alasan" rows="2" required minlength="5" class="field" placeholder="Contoh: pending BPJS, resume medis direvisi DPJP"></textarea>
                            <button type="submit" class="btn-secondary mt-2 w-full">Buka kembali berkas</button>
                        </form>
                    @endcan
                </section>
            @else
                {{-- Daftar cek --}}
                <section class="panel">
                    <div class="panel-head">
                        <h2 class="panel-title">Daftar cek petugas</h2>
                        @php $done = count(array_filter($claim->checklist ?? [])); @endphp
                        <span class="num text-xs text-tinta-3">{{ $done }}/{{ count(Eklaim::CHECKLIST) }}</span>
                    </div>
                    <form method="POST" action="{{ route('claims.checklist', $claim) }}" class="panel-body">
                        @csrf @method('PUT')
                        <ul class="space-y-2.5">
                            @foreach (Eklaim::CHECKLIST as $k => $label)
                                <li>
                                    <label class="flex gap-3 text-sm leading-snug">
                                        <input type="checkbox" name="items[{{ $k }}]" value="1" @checked(! empty($claim->checklist[$k])) @disabled(! $canEdit)
                                               class="mt-0.5 size-4 shrink-0 rounded border-garis-tua accent-giok">
                                        <span>{{ $label }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        @if ($claim->checklist_at)
                            <p class="mt-3 text-xs text-tinta-3">Terakhir disimpan {{ $claim->checklistUser?->name }}, {{ $claim->checklist_at->diffForHumans() }}</p>
                        @endif
                        @if ($canEdit)<button type="submit" class="btn-secondary mt-3 w-full">Simpan daftar cek</button>@endif
                    </form>
                </section>

                {{-- Persetujuan --}}
                <section class="panel">
                    <div class="panel-head"><h2 class="panel-title">Persetujuan</h2></div>
                    <div class="panel-body">
                        @if ($blockers)
                            <p class="text-sm text-tinta-2">Belum bisa disetujui:</p>
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($blockers as $b)
                                    <li class="flex gap-2"><span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-waspada"></span><span>{{ $b }}</span></li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-tinta-2">Semua syarat terpenuhi.</p>
                            @if ($completed && ($k = $completed->countBy(Severity::Kritis)))
                                <p class="mt-2 rounded-md bg-kritis-muda px-3 py-2 text-sm text-kritis">
                                    Masih ada {{ $k }} temuan kritis. Pastikan sudah ditindaklanjuti atau memang tidak berlaku.
                                </p>
                            @endif
                        @endif

                        @can('approve', $claim)
                            <form method="POST" action="{{ route('claims.approve', $claim) }}" class="mt-4"
                                  data-confirm="Setujui dan kunci berkas {{ $claim->nama_pasien }}?">
                                @csrf
                                <button type="submit" class="btn-stamp w-full py-2.5" @disabled($blockers)>Setujui berkas</button>
                            </form>
                        @else
                            @if (! $blockers)
                                <p class="mt-3 text-xs text-tinta-3">Persetujuan dilakukan oleh Koordinator casemix.</p>
                            @endif
                        @endcan
                    </div>
                </section>
            @endif

            {{-- Riwayat --}}
            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">Riwayat aktivitas</h2></div>
                @if ($activity->isEmpty())
                    <p class="px-5 py-4 text-sm text-tinta-3">Belum ada aktivitas.</p>
                @else
                    <ol class="max-h-96 space-y-3 overflow-y-auto px-5 py-4">
                        @foreach ($activity as $log)
                            <li class="grid grid-cols-[8px_1fr] gap-3 text-sm">
                                <span class="mt-1.5 size-2 rounded-full bg-garis-tua" aria-hidden="true"></span>
                                <div>
                                    <p class="leading-snug">{{ $log->description }}</p>
                                    <p class="text-xs text-tinta-3">{{ $log->user?->name ?? 'Sistem' }} · <time datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->format('d/m/Y H:i:s') }}">{{ $log->created_at->diffForHumans() }}</time></p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            @if ($claim->reviews->count() > 1)
                <details class="panel">
                    <summary class="cursor-pointer px-5 py-3.5 text-sm font-semibold">Pemeriksaan sebelumnya ({{ $claim->reviews->count() - 1 }})</summary>
                    <ul class="divide-y divide-garis border-t border-garis text-sm">
                        @foreach ($claim->reviews->skip(1) as $r)
                            <li class="px-5 py-2.5">
                                <span class="font-medium">{{ $r->status->label() }}</span>
                                <span class="text-tinta-3">· {{ $r->created_at->format('d/m/Y H:i') }} · {{ $r->requester?->name ?? 'Sistem' }}</span>
                                @if ($r->status === ReviewStatus::Selesai)
                                    <span class="block text-xs text-tinta-3">{{ $r->countBy(Severity::Kritis) }} kritis, {{ $r->countBy(Severity::Perhatian) }} perhatian, {{ count($r->suggestions ?? []) }} saran</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </aside>
    </div>
</x-app-layout>
