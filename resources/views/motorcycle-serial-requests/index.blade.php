@extends('layouts.dashboard')

@section('title', 'Solicitud de seriales de motos')
@section('description', 'Consulta de solicitudes de seriales de motos.')
@section('page-heading', 'Solicitud de seriales de motos')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:motorcycle-serial-request-list />
    </section>
@endsection
