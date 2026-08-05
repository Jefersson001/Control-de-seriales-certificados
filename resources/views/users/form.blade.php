@extends('layouts.dashboard')

@section('title', isset($user) ? 'Usuario' : 'Nuevo usuario')
@section('description', isset($user) ? 'Consulta o modificación del usuario.' : 'Registro de un nuevo usuario.')
@section('page-heading', isset($user) ? 'Usuario' : 'Nuevo usuario')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:user-manager :form-only="true" :user-id="$user->id ?? null" />
    </section>
@endsection
