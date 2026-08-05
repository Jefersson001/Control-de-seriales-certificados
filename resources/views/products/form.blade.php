@extends('layouts.dashboard')

@section('title', isset($product) ? 'Producto' : 'Nuevo producto')
@section('description', isset($product) ? 'Consulta o modificación del producto.' : 'Registro de un nuevo producto.')
@section('page-heading', isset($product) ? 'Producto' : 'Nuevo producto')

@section('content')
    <section class="mx-auto max-w-6xl">
        <livewire:product-manager :form-only="true" :product-id="$product->id ?? null" />
    </section>
@endsection
