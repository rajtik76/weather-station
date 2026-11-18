<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="color-scheme" content="dark">

        <title>{{ $title ?? config('app.name') }}</title>
        @php($description = 'Temperature, humidity and pressure from a balcony in Plzeň. A project to estimate outdoor air temperature by correcting radiation shield heating, using ČHMÚ Plzeň-Mikulka as a reference.')
        <meta name="description" content="{{ $description }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Balcony Station">
        <meta property="og:title" content="{{ $title ?? config('app.name') }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('og.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Balcony Station over the Plzeň skyline at sunset">
        <meta name="twitter:card" content="summary_large_image">

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
