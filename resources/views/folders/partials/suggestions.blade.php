{{-- Kotak saran untuk DPJP: terpisah dari Daftar periksa, tidak memengaruhi status Siap diajukan. Hanya tampil bila saran kondisi menyala. --}}
<section class="rounded-lg border border-purple-200 border-l-4 border-l-purple-600 bg-purple-50 p-4" aria-labelledby="judul-saran" data-saran>
    <div class="flex items-start gap-3">
        <svg class="mt-0.5 size-5 shrink-0 text-purple-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14" stroke-linecap="round"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>
        <div class="min-w-0">
            <h2 id="judul-saran" class="font-semibold text-purple-900">Kemungkinan belum tercatat di resume</h2>
            <p class="mt-0.5 text-sm text-purple-900/80">Ditemukan AI dari dokumen lain. Konfirmasi ke DPJP agar resume lengkap dan klaim tidak kurang. Tidak menghalangi status Siap diajukan.</p>
        </div>
    </div>

    @if ($suggestions->isEmpty())
        <p class="mt-3 text-sm text-purple-900/80">AI belum menemukan hal yang kemungkinan terlewat di resume.</p>
    @else
        <ol class="mt-4 space-y-3">
            @foreach ($suggestions as $s)
                @php
                    $idx = $s->dokumen !== null ? ($linkable[$s->dokumen] ?? null) : null;
                    $url = $idx !== null ? route('folders.file', [$folder, $idx]) . ($s->halaman ? '#page=' . $s->halaman : '') : null;
                    $badge = match ($s->status) {
                        'baru' => 'bg-purple-100 text-purple-800',
                        'ditanyakan' => 'bg-amber-100 text-amber-800',
                        'disetujui' => 'bg-green-100 text-green-800',
                        default => 'bg-gray-100 text-gray-700',
                    };
                @endphp
                <li class="rounded-md border border-purple-200 bg-white p-4" data-saran-item="{{ $s->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="font-medium text-arang">{{ $s->kondisi }}</p>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $badge }}">{{ \App\Models\ConditionSuggestion::STATUSES[$s->status] ?? $s->status }}</span>
                    </div>
                    @if ($s->bukti)
                        <p class="mt-1 text-sm text-abu">Bukti: “{{ $s->bukti }}”
                            @if ($url) · <a href="{{ $url }}" data-preview="{{ $s->dokumen }}" target="_blank" rel="noopener" class="text-url underline">{{ $s->dokumen }} hal. {{ $s->halaman ?: '–' }}</a>
                            @elseif ($s->dokumen) · {{ $s->dokumen }} hal. {{ $s->halaman ?: '–' }}@endif
                        </p>
                    @endif

                    <p class="mt-3 text-xs font-medium tracking-wide text-purple-900/70">Langkah tindak lanjut</p>
                    <ol class="mt-1 list-decimal space-y-2 pl-5 text-sm text-arang">
                        <li>Cek bukti di dokumen @if ($url)(<a href="{{ $url }}" data-preview="{{ $s->dokumen }}" target="_blank" rel="noopener" class="text-url underline">buka {{ $s->dokumen }} hal. {{ $s->halaman ?: '–' }}</a>)@endif.</li>
                        <li>
                            Hubungi DPJP.
                            <div class="mt-1 flex flex-wrap gap-2">
                                <button type="button" class="btn-secondary" data-salin="{{ $s->dpjpMessage($folder) }}" data-salin-ok="Pesan tersalin">Salin pesan untuk DPJP</button>
                                @can('folders.analyze')
                                    @if ($s->status === 'baru')
                                        <form method="POST" action="{{ route('folders.suggestion.status', [$folder, $s]) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="ditanyakan">
                                            <button type="submit" class="btn-ghost">Sudah ditanyakan ke DPJP</button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </li>
                        <li>
                            Catat jawaban DPJP.
                            @can('folders.analyze')
                                @unless ($s->answered())
                                    <form method="POST" action="{{ route('folders.suggestion.status', [$folder, $s]) }}" class="mt-1 space-y-2">
                                        @csrf
                                        <label for="catatan-{{ $s->id }}" class="sr-only">Catatan jawaban DPJP (opsional)</label>
                                        <input id="catatan-{{ $s->id }}" name="catatan" maxlength="500" class="field" placeholder="Catatan (opsional)">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit" name="status" value="disetujui" class="btn-secondary">DPJP setuju</button>
                                            <button type="submit" name="status" value="tidak_disetujui" class="btn-secondary">DPJP tidak setuju</button>
                                            <button type="submit" name="status" value="tidak_relevan" class="btn-ghost">Tidak relevan</button>
                                        </div>
                                    </form>
                                @endunless
                            @endcan
                            @if ($s->catatan_respons)<p class="mt-1 text-abu">Catatan: {{ $s->catatan_respons }}</p>@endif
                        </li>
                        <li>
                            @if ($s->status === 'disetujui')
                                DPJP melengkapi resume di Enigma, lalu koder mengkodekan dari resume. Setelah itu klik Analisa ulang.
                            @elseif ($s->answered())
                                Selesai, tidak ada perubahan.
                            @else
                                Bila DPJP setuju: DPJP melengkapi resume di Enigma, lalu koder mengkodekan dari resume. Setelah itu klik Analisa ulang. Bila tidak: selesai, tidak ada perubahan.
                            @endif
                        </li>
                    </ol>
                </li>
            @endforeach
        </ol>
    @endif

</section>
