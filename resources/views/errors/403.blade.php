<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Akses ditolak</title></head>
<body style="font-family:sans-serif;text-align:center;padding:4rem 1rem">
    <h1>403 — Akses ditolak</h1>
    <p>Akun Anda belum punya peran/izin untuk halaman ini.</p>
    @auth
        <p>Masuk sebagai: {{ auth()->user()->email }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Keluar</button>
        </form>
    @endauth
</body>
</html>
