@props(['managements'])

@php
    $dates = $managements->pluck('request_date')
        ->filter()
        ->unique(fn ($date) => $date->format('Y-m-d'))
        ->sort();
@endphp

@forelse ($dates as $date)
    <div>{{ $date->format('d/m/Y') }}</div>
@empty
    <span>Sin registrar</span>
@endforelse
