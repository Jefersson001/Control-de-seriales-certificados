@extends('layouts.dashboard')

@section('title', 'Maestro Seriales Certificados')
@section('description', 'Consulta del maestro de seriales certificados.')
@section('page-heading', 'Maestro Seriales Certificados')

@section('content')
    <section class="mx-auto max-w-screen-2xl">
        <livewire:certificate-master />
    </section>
@endsection
