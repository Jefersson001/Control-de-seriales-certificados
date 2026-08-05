@extends('layouts.dashboard')

@section('title', isset($motorcycleSerialRequest) ? 'Solicitud #'.$motorcycleSerialRequest->id : 'Nueva solicitud')
@section('description', 'Formulario de solicitud de seriales de motos.')
@section('page-heading', isset($motorcycleSerialRequest) ? 'Solicitud #'.$motorcycleSerialRequest->id : 'Nueva solicitud')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:motorcycle-serial-request-form :request-id="$motorcycleSerialRequest->id ?? null" />
    </section>
@endsection
