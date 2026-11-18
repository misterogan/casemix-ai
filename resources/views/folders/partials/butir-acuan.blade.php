{{-- Satu butir acuan regulasi: ringkasan, kutipan bergaris kiri, sumber (klik → penampil dokumen), jenis dokumen, label belum diperiksa. --}}
@php $d = $b->dokumen; @endphp
<li class="text-sm" data-butir-acuan="{{ $b->id }}" @if (! $b->diperiksa_manusia) data-belum-diperiksa @endif>
    <p class="flex flex-wrap items-center gap-1 text-arang">{{ $b->ringkasan }}
        <x-badge :variant="\App\Models\RegulasiDokumen::JENIS_TONE[$d->jenis] ?? 'gray'">{{ $d->jenisLabel() }}</x-badge>
        @if (! $b->diperiksa_manusia)<x-badge variant="warning">Belum diperiksa koder/dokter</x-badge>@endif
    </p>
    <blockquote class="mt-1 border-l-4 border-garis pl-3 text-xs italic text-tinta-3">“{{ $b->kutipan }}”</blockquote>
    <p class="mt-1 text-xs text-tinta-3">Sumber:
        <a class="text-url underline" href="{{ route('acuan.pnpk.pdf', $d) }}#page={{ $b->halamanBuka() }}" data-preview="{{ $d->namaPenampil() }}" target="_blank" rel="noopener">{{ $b->sitasi() }}</a></p>
</li>
