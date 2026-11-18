{{-- Dasar sanggahan (Koordinator): acuan BA / Permenkes / PNPK yang cocok, daftar periksa syarat, dan draf sanggahan AI.
     Variabel: $sanggahan (DasarSanggahan::susun), $drafUrl, $unduhUrl. --}}
@php $terkonfirmasi = $sanggahan['terkonfirmasi']; @endphp
<x-card lapang aria-labelledby="judul-sanggahan" data-dasar-sanggahan>
    <x-slot:header><h2 id="judul-sanggahan" class="text-base font-semibold text-gray-800 dark:text-white/90">Dasar sanggahan</h2></x-slot:header>

    @if (! $sanggahan['kelompok'] && $sanggahan['periksa']->isEmpty())
        <p class="text-[15px] text-abu">Belum ada acuan disetujui yang cocok dengan diagnosis, prosedur, atau INA-CBG kasus ini.</p>
    @endif

    @foreach ($sanggahan['kelompok'] as $judul => $isi)
        <h3 class="mt-4 text-xs font-medium tracking-wide text-tinta-3 first:mt-0">{{ $judul }}</h3>
        <ul class="mt-2 space-y-3">
            @foreach ($isi as $b)
                @include('folders.partials.butir-acuan')
            @endforeach
        </ul>
    @endforeach

    @if ($sanggahan['periksa']->isNotEmpty())
        <h3 class="mt-4 text-xs font-medium tracking-wide text-tinta-3">Daftar periksa berkas &amp; penunjang</h3>
        <ul class="mt-2 space-y-2 text-sm" data-daftar-periksa>
            @foreach ($sanggahan['periksa'] as $p)
                @php $b = $p['butir']; @endphp
                <li class="flex items-start gap-2">
                    <input type="checkbox" id="periksa-{{ $b->id }}" class="mt-1" @checked($p['otomatis']) data-periksa="{{ $b->id }}">
                    <label for="periksa-{{ $b->id }}" class="min-w-0">{{ $b->ringkasan }}
                        @if ($p['otomatis'])<span class="text-xs text-green-700">(terdeteksi di hasil analisa)</span>@endif
                        @if (! $b->diperiksa_manusia)<x-badge variant="warning">Belum diperiksa koder/dokter</x-badge>@endif
                        <a class="text-xs text-url underline" href="{{ route('acuan.pnpk.pdf', $b->dokumen) }}#page={{ $b->halamanBuka() }}" data-preview="{{ $b->dokumen->namaPenampil() }}" target="_blank" rel="noopener">{{ $b->sitasi() }}</a>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-5 border-t border-garis pt-4" data-draf data-draf-url="{{ $drafUrl }}">
        @if ($terkonfirmasi->isEmpty())
            <button type="button" class="btn-primary cursor-not-allowed opacity-50" disabled title="Belum ada acuan terkonfirmasi untuk kasus ini">Susun draf sanggahan</button>
            <p class="mt-1 text-[13px] text-gray-500" data-draf-nonaktif>Belum ada acuan terkonfirmasi untuk kasus ini</p>
        @else
            <button type="button" class="btn-primary" data-draf-susun data-butir="{{ $terkonfirmasi->pluck('id')->toJson() }}">Susun draf sanggahan</button>
            <p class="mt-1 text-[13px] text-gray-500">Memakai {{ $terkonfirmasi->count() }} butir yang sudah diperiksa koder/dokter.</p>
        @endif
        <p class="mt-2 text-sm text-red-700" role="alert" data-draf-galat hidden></p>
        <form method="POST" action="{{ $unduhUrl }}" class="mt-3 space-y-2" data-draf-hasil hidden>
            @csrf
            <p><x-badge variant="warning">Draf — periksa sebelum dikirim</x-badge></p>
            <label for="draf-teks" class="sr-only">Draf sanggahan</label>
            <textarea id="draf-teks" name="draf" rows="12" class="field w-full text-sm" data-draf-teks></textarea>
            <div class="flex gap-2">
                <button name="format" value="docx" class="btn-secondary">Unduh .docx</button>
                <button name="format" value="txt" class="btn-ghost">Unduh .txt</button>
            </div>
        </form>
    </div>
</x-card>
