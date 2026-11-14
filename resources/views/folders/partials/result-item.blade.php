{{-- Satu folder sebagai hasil pencarian: baris "URL", judul tautan, baris meta, chip status. --}}
@php
    $s = $f->state();
    $jenis = \App\Support\Eklaim::JENIS_RAWAT[$f->jenis_rawat] ?? 'Jenis rawat tidak terbaca';
    $temuan = collect([
        $f->kritis_count ? $f->kritis_count . ' kritis' : null,
        $f->perhatian_count ? $f->perhatian_count . ' perhatian' : null,
    ])->filter()->implode(' · ');
@endphp
<li class="py-4">
    <p class="truncate text-sm leading-5 text-url">FTP Enigma › {{ $f->name }}</p>
    <h3 class="mt-0.5 truncate">
        <a href="{{ route('folders.show', ($q ?? '') !== '' ? [$f, 'q' => $q] : $f) }}" class="judul-g">{{ $f->nama_pasien ?: $f->name }}</a>
    </h3>
    <p class="num mt-0.5 text-sm leading-5 text-abu">
        RM {{ $f->no_rm ?: '—' }} · SEP {{ $f->no_sep ?: '—' }} · {{ $jenis }} · {{ $f->file_count }} dokumen
        @if ($f->files_modified_at) · diperbarui di FTP {{ $f->files_modified_at->translatedFormat('d M Y') }}@endif
    </p>
    <div class="mt-2 flex">
        @switch($s)
            @case('temuan')
                <span class="chip {{ $f->kritis_count ? 'chip-kritis' : 'chip-waspada' }}">Sudah diperiksa AI · {{ $temuan }}</span>
                @break
            @case('lengkap')
                <span class="chip chip-sesuai">Sudah diperiksa AI · tidak ada temuan</span>
                @break
            @case('berubah')
                <span class="chip chip-berubah">File berubah sejak diperiksa</span>
                @break
            @case('proses')
                <span class="chip chip-proses"><span class="size-1.5 animate-pulse rounded-full bg-current motion-reduce:animate-none" aria-hidden="true"></span>Sedang diperiksa AI…</span>
                @break
            @case('gagal')
                <span class="chip chip-kritis">Pemeriksaan AI gagal</span>
                @break
            @default
                <span class="chip chip-belum">Belum diperiksa AI</span>
        @endswitch
    </div>
</li>
