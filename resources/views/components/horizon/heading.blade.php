@props(['id'])

<h2 id="{{ $id }}" {{ $attributes->class('hz-h2') }}>{{ $slot }}</h2>
