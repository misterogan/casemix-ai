{{-- Dasar koding (Koordinator): per kode ICD-10 (DU/DS) & ICD-9-CM, butir acuan disetujui yang cocok + kaidah umum posisinya. --}}
@php $dk = app(\App\Services\Regulasi\DasarKoding::class); @endphp
<x-card lapang aria-labelledby="judul-dasar-koding" data-dasar-koding>
    <x-slot:header><h2 id="judul-dasar-koding" class="text-base font-semibold text-gray-800 dark:text-white/90">Dasar koding</h2></x-slot:header>
    @if ($dasarKoding->isNotEmpty())
        <x-slot:actions><button type="button" class="btn-secondary btn-sm h-9" data-salin="{{ $dk->teksSalin($dasarKoding) }}" data-salin-ok="Dasar koding tersalin"
            data-salin-audit="{{ route('folders.dasar_koding.salin', $folder) }}">Salin dasar koding</button></x-slot:actions>
    @endif
    @if ($dasarKoding->isEmpty())
        <p class="text-[15px] text-abu">Belum ada kode ICD dari analisa atau koder untuk folder ini.</p>
    @else
        <ul class="space-y-4">
            @foreach ($dasarKoding as $x)
                <li class="rounded-xl border border-gray-200 p-4" data-dasar-kode="{{ $x['kode'] }}">
                    <p><strong class="num font-bold">{{ $x['kode'] }}</strong> {{ $x['judul'] }}
                        <span class="text-xs text-tinta-3">· {{ \App\Services\Regulasi\DasarKoding::PERAN[$x['peran']] ?? $x['peran'] }}</span></p>
                    @if ($x['tidak_di_who'])
                        <p class="mt-2 rounded-md bg-red-50 px-3 py-2 text-sm font-medium text-red-800" role="alert" data-bukan-who>Kode tidak ada di ICD-10 WHO 2010 (kemungkinan ICD-10-CM)</p>
                    @endif
                    @if ($x['butir']->isEmpty())
                        <p class="mt-2 text-[13px] text-gray-500">Belum ada acuan disetujui untuk kode ini.</p>
                    @else
                        <ul class="mt-2 space-y-3">
                            @foreach ($x['butir'] as $b)
                                @include('folders.partials.butir-acuan')
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-card>
