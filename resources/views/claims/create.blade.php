<x-app-layout title="Buat berkas">
    <x-page-header title="Buat berkas klaim" subtitle="Isi data sesuai SEP dan resume medis. Dokumen diunggah setelah berkas dibuat." :back="route('claims.index')"/>
    <form method="POST" action="{{ route('claims.store') }}">
        @csrf
        @include('claims._form')
        <div class="sticky bottom-0 mt-6 -mx-5 flex justify-end gap-2 border-t border-garis bg-kertas/95 px-5 py-3 backdrop-blur lg:-mx-8 lg:px-8">
            <a href="{{ route('claims.index') }}" class="btn-ghost">Batal</a>
            <button type="submit" class="btn-primary">Buat berkas</button>
        </div>
    </form>
</x-app-layout>
