<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>
        <meta name="description" content="A balcony weather station that forecasts its own next six hours and scores each forecast against what it then measured.">

        {{-- The mark is the three channel traces; the .ico carries the small
             sizes for browsers that will not take the SVG. --}}
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- The families are bundled by bunny() in vite.config.js; @vite does
             not emit them, this does. --}}
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-[#eef3f8] dark:bg-[#0a0f1c]">
        {{ $slot }}

        @livewireScripts
        @fluxScripts
    </body>
</html>
