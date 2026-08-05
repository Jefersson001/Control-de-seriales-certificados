@extends('layouts.dashboard')

@section('title', 'Constancia de Registro de Número de Identificación de Vehículo')
@section('description', 'Gestión de constancias de registro del número de identificación de vehículos.')
@section('page-heading', 'Constancia de Registro de Número de Identificación de Vehículo')

@section('content')
    <section class="mx-auto max-w-screen-2xl">
        <livewire:vehicle-identification-record-import />
    </section>
@endsection
