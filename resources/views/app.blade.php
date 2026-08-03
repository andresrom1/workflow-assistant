<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Lo comparte el middleware `noindex` en las páginas cuyo link es la única credencial. --}}
    @if ($noindex ?? false)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <title inertia>{{ config('app.name', 'PAS Mobile') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    {{--
      Script de inicialización de tema — corre ANTES del primer paint
      para evitar el flash de modo incorrecto (FOUC).
      Lee localStorage; si no hay valor, detecta la preferencia del SO.
    --}}
    <script>
      (function () {
        var stored = localStorage.getItem('pas-theme');
        var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
      })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
