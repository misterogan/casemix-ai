<x-app-layout title="Unggah manual">
    <div class="mx-auto mt-6 max-w-3xl space-y-6">
        <header>
            <h1 class="text-[26px] leading-8 font-normal text-arang">Unggah manual</h1>
            <p class="mt-1 text-sm text-abu">Dokumen dianalisa sama seperti folder FTP. Keputusan koding tetap pada koder/DPJP.</p>
        </header>

        <section class="panel space-y-4" data-manual-form data-lookup-url="{{ route('manual.lookup') }}">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm">No. SEP <span class="text-abu">(opsional, 19 karakter)</span>
                    <input name="no_sep" maxlength="19" class="mt-1 w-full rounded-md border-gray-300" data-field="no_sep" autocomplete="off">
                </label>
                <label class="block text-sm">No. RM <span class="text-abu">(wajib bila SEP kosong)</span>
                    <input name="no_rm" maxlength="30" class="mt-1 w-full rounded-md border-gray-300" data-field="no_rm">
                </label>
                <label class="block text-sm">Jenis pelayanan
                    <select name="jenis_rawat" class="mt-1 w-full rounded-md border-gray-300" data-field="jenis_rawat">
                        <option value="inap">Rawat inap</option>
                        <option value="jalan">Rawat jalan</option>
                    </select>
                </label>
                <label class="block text-sm">Tanggal pulang <span class="text-abu">(opsional)</span>
                    <input type="date" name="tgl_pulang" class="mt-1 w-full rounded-md border-gray-300" data-field="tgl_pulang">
                </label>
            </div>
            <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-arang" data-vclaim hidden></p>
            <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm" data-existing hidden>
                <p>Folder untuk SEP ini sudah ada: <a class="underline" data-existing-link></a></p>
                <button type="button" class="btn-secondary mt-2" data-use-existing>Tambahkan ke folder itu</button>
            </div>
            <label class="block text-sm">Catatan <span class="text-abu">(opsional)</span>
                <textarea name="catatan" rows="2" maxlength="1000" class="mt-1 w-full rounded-md border-gray-300" data-field="catatan"></textarea>
            </label>
        </section>

        <section class="panel">
            @include('unggah._uploader')
        </section>
    </div>
    @vite('resources/js/manual-upload.js')
</x-app-layout>
