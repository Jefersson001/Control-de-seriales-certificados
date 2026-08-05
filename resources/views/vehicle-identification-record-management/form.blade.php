@extends('layouts.dashboard')

@section('title', 'Gestión NIV #'.$management->id)
@section('description', 'Formulario de gestión de constancia de registro NIV.')
@section('page-heading', 'Gestión NIV #'.$management->id)

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:vehicle-identification-record-management-form :management-id="$management->id" />
    </section>
@endsection
