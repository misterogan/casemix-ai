{{-- Tabel: header 12px gray-500 latar gray-50, baris 14px ±56px, garis gray-100, hover gray-25.
     Slot head = <tr><th>…</th></tr>; slot utama = baris <tr>. :checkbox="true" menambah kolom "pilih semua". --}}
@props(['checkbox' => false])
<div {{ $attributes->class('max-w-full overflow-x-auto') }}>
    <table class="table min-w-full">
        @isset($head)
            <thead>
                @if ($checkbox)
                    {!! preg_replace('/<tr([^>]*)>/', '<tr$1><th class="w-10"><input type="checkbox" class="size-4 rounded border-gray-300 accent-brand-500" data-cek-semua aria-label="Pilih semua"></th>', (string) $head, 1) !!}
                @else
                    {{ $head }}
                @endif
            </thead>
        @endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
