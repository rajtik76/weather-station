<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="color-scheme" content="dark">

        <title>{{ $title ?? config('app.name') }}</title>
        <meta name="description" content="Temperature, humidity and pressure from a balcony in Plzeň. A project to estimate outdoor air temperature by correcting radiation shield heating, using ČHMÚ Plzeň-Mikulka as a reference.">

        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 64x64">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml" sizes="any">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles

        @if (config('services.umami.website_id'))
            <script defer src="https://stats.rajtik.com/script.js" data-website-id="{{ config('services.umami.website_id') }}" data-domains="weather.rajtik.com"></script>
        @endif
    </head>
    <body class="min-h-screen bg-[#060914]">
        {{ $slot }}

        @livewireScripts
        @fluxScripts
    </body>
</html>
