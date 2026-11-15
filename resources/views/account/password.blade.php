<x-app-layout title="Ganti password">
    <x-page-header title="Ganti password"/>
    <form method="POST" action="{{ route('account.password.update') }}" class="panel max-w-md">
        @csrf
        @method('PUT')
        <div class="panel-body grid gap-4">
            @foreach ([
                ['current_password', 'Password saat ini', 'current-password', null],
                ['password', 'Password baru', 'new-password', 'Minimal 10 karakter, berisi huruf dan angka'],
                ['password_confirmation', 'Ulangi password baru', 'new-password', null],
            ] as [$name, $label, $autocomplete, $hint])
                <x-field :name="$name" :label="$label" :hint="$hint" required>
                    <div class="relative">
                        <input id="{{ $name }}" name="{{ $name }}" type="password"
                               autocomplete="{{ $autocomplete }}" required class="field pr-10">
                        <button type="button" class="absolute inset-y-0 right-0 grid w-10 place-items-center text-abu hover:text-arang" title="Lihat"
                                onclick="const i=document.getElementById('{{ $name }}'),s=i.type==='password';i.type=s?'text':'password';this.title=s?'Sembunyikan':'Lihat';this.querySelectorAll('svg').forEach(e=>e.classList.toggle('hidden'))">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="hidden size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.2M6.1 6.1C3.6 7.8 2 12 2 12s3.5 7 10 7a10 10 0 005.9-1.9"/></svg>
                            <span class="sr-only">Lihat/sembunyikan password</span>
                        </button>
                    </div>
                </x-field>
            @endforeach
        </div>
        <div class="flex justify-end gap-2 border-t border-garis px-5 py-3">
            <button type="submit" class="btn-primary">Simpan password</button>
        </div>
    </form>
</x-app-layout>
