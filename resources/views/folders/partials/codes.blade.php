{{-- Saran diagnosis & kode ICD dari AI. Hanya tampil bila ai_code_suggestions menyala. Tidak memengaruhi status Siap diajukan. --}}
@php
    $kelompok = ['utama' => 'Diagnosis utama', 'sekunder' => 'Diagnosis sekunder', 'tindakan' => 'Tindakan (ICD-9-CM)'];
    $statusLabel = ['ditanyakan' => 'Sudah ditanyakan', 'setuju' => 'DPJP setuju', 'tidak_setuju' => 'DPJP tidak setuju', 'tidak_relevan' => 'Tidak relevan'];
    $pasien = sprintf('%s (RM %s, SEP %s)', $folder->nama_pasien ?: $folder->name, $folder->no_rm ?: '-', $folder->no_sep ?: '-');
    $daftarKoder = $codes->reject(fn ($c) => in_array($c->status_tindak_lanjut, ['tidak_setuju', 'tidak_relevan'], true))
        ->sortBy(fn ($c) => array_search($c->peran, array_keys($kelompok), true))
        ->map(fn ($c) => sprintf('%s: %s %s%s', $kelompok[$c->peran] ?? $c->peran, $c->code, $c->title,
            ! $c->tercantum_di_resume && $c->status_tindak_lanjut !== 'setuju' ? ' (menunggu DPJP)' : ''))
        ->implode("\n");
@endphp
<section class="panel" aria-labelledby="judul-kode" data-kode>
    <div class="panel-head flex flex-wrap items-center justify-between gap-2">
        <h2 id="judul-kode" class="panel-title">Saran diagnosis &amp; kode</h2>
        @if ($codes->isNotEmpty())
            <button type="button" class="btn-secondary" data-salin="{{ $daftarKoder }}" data-salin-ok="Daftar kode tersalin">Salin daftar kode untuk koder</button>
        @endif
    </div>

    <div class="px-5 py-4 text-sm">
        @if ($codes->isEmpty())
            <p class="text-abu">Belum ada saran kode dari AI untuk folder ini.</p>
        @else
            @foreach ($kelompok as $peran => $judul)
                @php $baris = $codes->where('peran', $peran); @endphp
                @continue($baris->isEmpty())
                <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-tinta-3 first:mt-0">{{ $judul }}</h3>
                <ul class="mt-1 space-y-2">
                    @foreach ($baris as $c)
                        @php
                            $dok = $c->document?->name;
                            $url = $tautan($dok, $c->halaman);
                            $oranye = ! $c->tercantum_di_resume;
                            $pesan = sprintf('Dok, mohon konfirmasi untuk pasien %s. Di %s hal. %s tercatat: %s. Apakah %s perlu dicantumkan di resume medis? Bila ya, mohon dilengkapi di Enigma. Bila tidak, tidak perlu ada perubahan. Terima kasih.',
                                $pasien, $dok ?: 'dokumen', $c->halaman, rtrim($c->bukti, '. '), $c->title);
                        @endphp
                        <li class="rounded-md border p-3 {{ $oranye ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }}" data-kode-item="{{ $c->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p><strong class="num">{{ $c->code }}</strong> {{ $c->title }}</p>
                                @if ($oranye)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Hanya di {{ $c->sumber }} — perlu konfirmasi DPJP</span>
                                @else
                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Sama dengan resume</span>
                                @endif
                            </div>
                            @if ($c->alasan)<p class="mt-1 text-arang">{{ $c->alasan }}</p>@endif
                            @if ($c->kaidah_koding)<p class="mt-0.5 text-xs text-abu">Kaidah: {{ $c->kaidah_koding }}</p>@endif
                            <p class="mt-1 text-xs text-abu">Sumber: {{ $c->sumber }} · Keyakinan: {{ $c->keyakinan }}</p>
                            <p class="mt-1 text-abu">Bukti: “{{ $c->bukti }}”
                                @if ($url) · <a href="{{ $url }}" data-preview="{{ $dok }}" target="_blank" rel="noopener" class="text-url underline">{{ $dok }} hal. {{ $c->halaman }}</a>
                                @elseif ($dok) · {{ $dok }} hal. {{ $c->halaman }}@endif
                            </p>

                            @if ($oranye)
                                @if ($c->status_tindak_lanjut)<p class="mt-2 text-xs font-medium text-amber-900">Status: {{ $statusLabel[$c->status_tindak_lanjut] ?? $c->status_tindak_lanjut }}</p>@endif
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" class="btn-secondary" data-salin="{{ $pesan }}" data-salin-ok="Pesan tersalin">Salin pesan untuk DPJP</button>
                                    @can('folders.analyze')
                                        @if ($c->status_tindak_lanjut === null)
                                            <form method="POST" action="{{ route('folders.code.status', [$folder, $c]) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="ditanyakan">
                                                <button type="submit" class="btn-ghost">Sudah ditanyakan ke DPJP</button>
                                            </form>
                                        @endif
                                        @if (in_array($c->status_tindak_lanjut, [null, 'ditanyakan'], true))
                                            <form method="POST" action="{{ route('folders.code.status', [$folder, $c]) }}" class="flex flex-wrap gap-2">
                                                @csrf
                                                <button type="submit" name="status" value="setuju" class="btn-secondary">DPJP setuju</button>
                                                <button type="submit" name="status" value="tidak_setuju" class="btn-secondary">DPJP tidak setuju</button>
                                                <button type="submit" name="status" value="tidak_relevan" class="btn-ghost">Tidak relevan</button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        @endif
        <p class="mt-4 text-xs text-abu">Saran ini bantuan, bukan keputusan koding. Koding final oleh koder di Edit Koreksi E-Klaim berdasarkan resume DPJP.</p>
    </div>
</section>
