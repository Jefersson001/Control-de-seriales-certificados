@extends('layouts.dashboard')

@section('title', 'Devoluciones')
@section('description', 'Gestión de devoluciones del sistema.')
@section('page-heading', 'Devoluciones')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:return-list />
    </section>
@endsection
