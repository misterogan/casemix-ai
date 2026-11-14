{{-- Satu file dalam folder sebagai item accordion. Dipakai saat halaman dibuka dan oleh endpoint status (polling). --}}
@php
    $st = $d->status->value;
    $temuan = collect($d->findings ?? []);
    $masalah = $temuan->whereIn('tingkat', ['kritis', 'perhatian'])->count();
    $buka = $st === 'selesai' && $masalah > 0;
    $idx = $linkable[$d->name] ?? null;
    $isi = $d->summary ?? [];
    $catatan = match ($st) {
        'selesai' => $d->reused ? 'tidak berubah' : ($temuan->count() ? $temuan->count() . ' temuan' : ($d->error ?: 'tidak ada temuan')),
        'gagal' => \Illuminate\Support\Str::limit((string) $d->error, 90),
        'dibatalkan' => 'Tidak diproses karena dokumen sebelumnya gagal',
        'berjalan' => 'sedang dibaca',
        default => 'menunggu',
    };
    $daftar = fn ($v) => collect(is_array($v) ? $v : [])->filter(fn ($x) => is_string($x) && $x !== '')->values();
    $biaya = collect(is_array($isi['biaya'] ?? null) ? $isi['biaya'] : [])->filter(fn ($b) => is_array($b) && filled($b['komponen'] ?? null));
@endphp
<details id="dok-{{ $d->id }}" data-doc="{{ $d->id }}" data-doc-status="{{ $st }}" class="group rounded-lg border border-gray-200 bg-white" @if ($buka) open @endif>
    <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
        <span class="shrink-0" aria-hidden="true">
            @switch($st)
                @case('selesai')
                    <svg class="size-5 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                    @break
                @case('gagal')
                    <svg class="size-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    @break
                @case('dibatalkan')
                    <svg class="size-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 12h12"/></svg>
                    @break
                @case('berjalan')
                    <svg class="size-5 text-abu motion-safe:animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    @break
                @default
                    <svg class="size-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            @endswitch
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium {{ $st === 'dibatalkan' ? 'text-gray-400' : '' }}">{{ $d->name }}</span>
            <span class="block text-xs {{ $st === 'gagal' ? 'text-red-700' : 'text-abu' }}">{{ $d->typeLabel() }} · {{ $catatan }}</span>
        </span>
        <span class="sr-only">Status: {{ $catatan }}</span>
        <svg class="size-4 shrink-0 text-abu transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </summary>

    <div class="space-y-3 border-t border-gray-200 px-4 py-3 text-sm" data-doc-body>
        @if ($st === 'gagal')
            <p class="text-red-700">{{ $d->error }}</p>
        @elseif ($st === 'dibatalkan')
            <p class="text-gray-500">Tidak diproses karena dokumen sebelumnya gagal.</p>
        @elseif ($st === 'selesai' && $d->error)
            <p class="text-abu">{{ $d->error }}</p>
        @elseif (in_array($st, ['antre', 'berjalan'], true))
            <p class="text-abu">{{ $st === 'berjalan' ? 'AI sedang membaca file ini.' : 'Menunggu giliran dibaca.' }}</p>
        @endif

        @if ($temuan->isNotEmpty())
            <ul class="space-y-2">
                @foreach ($temuan as $f)
                    <li>
                        <span class="font-medium {{ $f['tingkat'] === 'kritis' ? 'text-red-700' : 'text-amber-700' }}">{{ ucfirst($f['tingkat']) }}:</span>
                        {{ $f['judul'] }}
                        @if ($f['halaman'] ?? null)
                            @if ($idx !== null)
                                <a href="{{ route('folders.file', [$folder, $idx]) }}#page={{ $f['halaman'] }}" target="_blank" rel="noopener" class="text-abu hover:underline">hal. {{ $f['halaman'] }} ↗</a>
                            @else
                                <span class="text-abu">hal. {{ $f['halaman'] }}</span>
                            @endif
                        @endif
                        @if ($f['uraian'] ?? null)<span class="block text-abu">{{ $f['uraian'] }}</span>@endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($daftar($isi['diagnosis'] ?? null)->isNotEmpty() || $daftar($isi['tindakan'] ?? null)->isNotEmpty() || $biaya->isNotEmpty())
            <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[8rem_1fr]">
                @if ($daftar($isi['diagnosis'] ?? null)->isNotEmpty())
                    <dt class="text-abu">Diagnosis</dt><dd>{{ $daftar($isi['diagnosis'])->implode('; ') }}</dd>
                @endif
                @if ($daftar($isi['tindakan'] ?? null)->isNotEmpty())
                    <dt class="text-abu">Tindakan</dt><dd>{{ $daftar($isi['tindakan'])->implode('; ') }}</dd>
                @endif
                @if ($biaya->isNotEmpty())
                    <dt class="text-abu">Biaya</dt>
                    <dd>{{ $biaya->map(fn ($b) => $b['komponen'] . ' ' . (is_numeric($b['nilai'] ?? null) ? 'Rp' . number_format((float) $b['nilai'], 0, ',', '.') : ($b['nilai'] ?? '')))->implode('; ') }}</dd>
                @endif
            </dl>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            @if ($idx !== null)
                <a href="{{ route('folders.file', [$folder, $idx]) }}" target="_blank" rel="noopener" class="hover:underline">Buka dokumen ↗</a>
            @endif
            @can('folders.analyze')
                <form method="POST" action="{{ route('folders.process_document', [$folder, $d]) }}">
                    @csrf
                    <button type="submit" class="btn-secondary text-sm" @if ($berjalan && ! $blocker) data-lock @endif @disabled($berjalan || $blocker)>Proses ulang file ini</button>
                </form>
            @endcan
        </div>
    </div>
</details>
