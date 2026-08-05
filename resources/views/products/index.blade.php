@extends('layouts.dashboard')

@section('title', 'Productos')
@section('description', 'Consulta y administración de productos del sistema.')
@section('page-heading', 'Productos')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:product-manager />
    </section>
@endsection
