@php
    $syncAt = $lastSync['at'] ?? null;
    $mode = $q !== '' ? 'hasil' : 'beranda';
@endphp
<x-app-layout title="Verifikasi berkas">
    <div class="mx-auto max-w-[720px]" data-cari-wadah data-mode="{{ $mode }}">
        <h1 class="cari-judul">Verifikasi berkas</h1>

        {{-- Tetap bekerja tanpa JavaScript: Enter dan tombol Cari mengirim GET biasa --}}
        <form method="GET" action="{{ route('folders.index') }}" role="search" data-cari-form>
            @if ($state)<input type="hidden" name="status" value="{{ $state }}">@endif
            <div class="cari">
                <button type="submit" class="cari-ikon" aria-label="Cari">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
                </button>
                <label for="q" class="sr-only">Cari folder pasien</label>
                <input id="q" name="q" type="search" value="{{ $q }}" class="cari-input"
                       placeholder="Cari No. SEP, No. RM, atau nama pasien" autocomplete="off" spellcheck="false"
                       @if ($q === '') autofocus @endif data-cari-input>
                <a href="{{ route('folders.index', $state ? ['status' => $state] : []) }}" class="cari-hapus"
                   aria-label="Hapus kata kunci" data-cari-hapus @if ($q === '') hidden @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/></svg>
                </a>
            </div>
            <div class="cari-tombol"><button type="submit" class="btn-secondary rounded-full px-5">Cari</button></div>
        </form>

        <p class="cari-meta">
            @if ($syncAt)
                Daftar folder dari FTP DHealth diperbarui otomatis tiap jam · terakhir {{ \Illuminate\Support\Carbon::parse($syncAt)->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}
                @if (! ($lastSync['ok'] ?? true))<span class="text-kritis"> · gagal terhubung ke FTP, dicoba lagi jam berikutnya</span>@endif
            @else
                Daftar folder dari FTP DHealth diperbarui otomatis tiap jam · belum pernah tersinkron
            @endif
        </p>

        <div class="mt-6" data-hasil aria-busy="false">
            @include('folders.partials.results')
        </div>

        {{-- Kerangka saat memuat: setinggi baris hasil asli supaya tata letak tidak bergeser --}}
        <template data-kerangka>
            <p class="sr-only">Memuat hasil…</p>
            @for ($i = 0; $i < 4; $i++)
                <div class="animate-pulse py-4 motion-reduce:animate-none" aria-hidden="true">
                    <div class="flex h-5 items-center"><div class="h-3 w-56 max-w-full rounded-full bg-abu-muda"></div></div>
                    <div class="mt-0.5 flex h-[26px] items-center"><div class="h-4 w-72 max-w-full rounded-full bg-[#E8EAED]"></div></div>
                    <div class="mt-0.5 flex h-5 items-center"><div class="h-3 w-[26rem] max-w-full rounded-full bg-abu-muda"></div></div>
                    <div class="mt-2 h-[26px] w-48 rounded-full bg-abu-muda"></div>
                </div>
            @endfor
        </template>
    </div>
</x-app-layout>
