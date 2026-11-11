<x-guest-layout>
<div class="grid min-h-screen lg:grid-cols-[1fr_minmax(420px,520px)]">
    <section class="relative hidden overflow-hidden bg-hutan p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div class="flex items-center gap-2.5">
            <svg viewBox="0 0 32 32" class="size-9" aria-hidden="true">
                <rect x="3" y="7" width="26" height="20" rx="3" fill="#EADFC4"/>
                <path d="M3 10a3 3 0 013-3h7l3 3h10a3 3 0 013 3" fill="none" stroke="#D8C89F" stroke-width="2"/>
                <path d="M10 18l4 4 8-9" fill="none" stroke="#0E6B57" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="text-lg font-bold">{{ config('casemix.app_name') }}</span>
        </div>

        <div class="max-w-lg">
            <p class="text-[40px] leading-[1.1] font-bold tracking-tight">Berkas lengkap dan konsisten sebelum masuk E-Klaim.</p>
            <p class="mt-5 max-w-md text-[15px] leading-relaxed text-white/70">
                AI membantu mencocokkan SEP, resume medis, penunjang, dan rincian biaya. Keputusan akhir tetap pada petugas casemix.
            </p>
        </div>

        {{-- Tumpukan map berkas sebagai ilustrasi --}}
        <div class="relative h-40" aria-hidden="true">
            <div class="absolute bottom-0 left-0 h-28 w-80 rotate-[-4deg] rounded-md bg-manila-tua"></div>
            <div class="absolute bottom-3 left-6 h-28 w-80 rotate-[2deg] rounded-md bg-manila">
                <div class="absolute -top-4 left-4 h-5 w-24 rounded-t-md bg-manila"></div>
                <div class="absolute top-6 left-6 space-y-2">
                    <div class="h-2 w-40 rounded bg-hutan/15"></div>
                    <div class="h-2 w-52 rounded bg-hutan/15"></div>
                    <div class="h-2 w-32 rounded bg-hutan/15"></div>
                </div>
                <div class="stamp absolute right-6 bottom-5 !text-[13px]">DISETUJUI</div>
            </div>
        </div>
    </section>

    <section class="flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-sm">
            <h1 class="text-2xl font-bold">Masuk</h1>
            <p class="mt-1 text-sm text-tinta-2">{{ config('casemix.hospital_name') }} · Unit Casemix</p>

            @if ($errors->any())
                <div class="mt-5 rounded-md border border-kritis/25 bg-kritis-muda px-4 py-3 text-sm text-kritis">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field">
                </div>
                <div>
                    <label for="password" class="label">Kata sandi</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" class="field">
                </div>
                <label class="flex items-center gap-2 text-sm text-tinta-2">
                    <input type="checkbox" name="remember" class="size-4 rounded border-garis-tua accent-giok"> Ingat saya di komputer ini
                </label>
                <button type="submit" class="btn-primary w-full py-2.5">Masuk</button>
            </form>
            <p class="mt-8 text-xs leading-relaxed text-tinta-3">Akun dibuat oleh Admin. Lupa kata sandi? Hubungi Admin aplikasi.</p>
        </div>
    </section>
</div>
</x-guest-layout>
