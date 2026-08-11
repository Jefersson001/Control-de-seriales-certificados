@extends('layouts.dashboard')

@section('title', isset($dispatch) ? 'Despacho '.$dispatch->name : 'Nuevo despacho')
@section('description', 'Formulario de despacho de seriales certificados.')
@section('page-heading', isset($dispatch) ? 'Despacho '.$dispatch->name : 'Nuevo despacho')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:dispatch-form :dispatch-id="$dispatch->id ?? null" />
    </section>
@endsection
