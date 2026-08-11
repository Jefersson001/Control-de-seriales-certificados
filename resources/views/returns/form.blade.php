@extends('layouts.dashboard')

@section('title', isset($return) ? 'Devolución '.$return->name : 'Nueva devolución')
@section('description', 'Formulario de devolución de seriales certificados.')
@section('page-heading', isset($return) ? 'Devolución '.$return->name : 'Nueva devolución')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:return-form :return-id="$return->id ?? null" />
    </section>
@endsection