@extends('layouts.dashboard')

@section('title', 'Usuarios')
@section('description', 'Administración de usuarios del sistema.')
@section('page-heading', 'Usuarios')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:user-manager />
    </section>
@endsection
