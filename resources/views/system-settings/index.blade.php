@extends('layouts.dashboard')

@section('title', 'Parámetros del sistema')
@section('description', 'Configuración de límites y parámetros generales del sistema.')
@section('page-heading', 'Parámetros del sistema')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:system-settings />
    </section>
@endsection
