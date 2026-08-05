@extends('layouts.dashboard')

@section('title', 'Gestión de Constancia de Registro de Número de Identificación de Vehículo')
@section('description', 'Gestión de constancias generadas desde solicitudes de seriales de motos.')
@section('page-heading', 'Gestión de Constancia de Registro de Número de Identificación de Vehículo')

@section('content')
    <section class="mx-auto max-w-7xl">
        <livewire:vehicle-identification-record-management-list />
    </section>
@endsection
