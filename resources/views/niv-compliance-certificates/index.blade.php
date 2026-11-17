@extends('layouts.dashboard')

@section('title', 'Constancias de cumplimiento NIV')
@section('description', 'Consulta las constancias de composición y ubicación del NIV.')
@section('page-heading', 'Constancias de cumplimiento NIV')

@section('content')
    <livewire:niv-compliance-certificate-list />
@endsection
