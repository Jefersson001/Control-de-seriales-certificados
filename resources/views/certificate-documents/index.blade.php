@extends('layouts.dashboard')

@section('title', 'Certificados')
@section('description', 'Consulta de certificados PDF procesados por el sistema.')
@section('page-heading', 'Certificados')

@section('content')
    <section class="mx-auto max-w-screen-2xl">
        <livewire:certificate-document-list />
    </section>
@endsection
