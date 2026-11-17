@extends('layouts.dashboard')

@section('title', isset($certificate) ? 'Constancia de cumplimiento NIV' : 'Nueva constancia de cumplimiento NIV')
@section('description', 'Carga el PDF y revisa los datos extraídos de la constancia.')
@section('page-heading', 'Constancia de cumplimiento NIV')

@section('content')
    <livewire:niv-compliance-certificate-form :certificate-id="$certificate->id ?? null" />
@endsection
