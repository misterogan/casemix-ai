<x-app-layout title="Ubah {{ $claim->nama_pasien }}">
    <x-page-header title="Ubah data klaim" :subtitle="$claim->nama_pasien . ' · RM ' . $claim->no_rm" :back="route('claims.show', $claim)"/>

    @if ($claim->reviews()->exists())
        <div class="mb-5 rounded-md border border-waspada/25 bg-waspada-muda px-4 py-3 text-sm text-waspada">
            Mengubah data akan membuat hasil pemeriksaan AI usang dan mengosongkan daftar cek. Jalankan pemeriksaan ulang setelah menyimpan.
        </div>
    @endif

    <form method="POST" action="{{ route('claims.update', $claim) }}">
        @csrf @method('PUT')
        @include('claims._form')
        <div class="sticky bottom-0 mt-6 -mx-5 flex justify-end gap-2 border-t border-garis bg-kertas/95 px-5 py-3 backdrop-blur lg:-mx-8 lg:px-8">
            <a href="{{ route('claims.show', $claim) }}" class="btn-ghost">Batal</a>
            <button type="submit" class="btn-primary">Simpan perubahan</button>
        </div>
    </form>
</x-app-layout>
