<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · {{ config('casemix.app_name') }}</title>
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('tema'); } catch (e) {}
            if (t === 'dark' || (t === null && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark');
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-gray-950">
    {{ $slot }}
</body>
</html>
