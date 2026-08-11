@extends('layouts.dashboard')

@section('title', 'Despacho')
@section('description', 'Gestión de despachos del sistema.')
@section('page-heading', 'Despacho')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:dispatch-list />
    </section>
@endsection
