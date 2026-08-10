@extends('layouts.dashboard')

@section('title', 'Despacho')
@section('description', 'Gestión de despachos del sistema.')
@section('page-heading', 'Despacho')

@section('content')
    <section class="mx-auto max-w-6xl">
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
            <div class="border-b border-slate-200 bg-slate-50/70 px-6 py-6 dark:border-white/10 dark:bg-slate-950/30 sm:px-8">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Despacho</p>
                <h2 class="mt-3 text-2xl font-semibold">Gestión de despachos</h2>
                <p class="mt-2 max-w-3xl leading-7 text-slate-600 dark:text-slate-400">
                    Este módulo está preparado para incorporar el flujo, los campos y las operaciones de despacho.
                </p>
            </div>
            <div class="p-6 sm:p-8">
                <div class="rounded-2xl border border-dashed border-indigo-300 bg-indigo-50/60 p-8 text-center dark:border-indigo-500/30 dark:bg-indigo-500/10">
                    <h3 class="text-lg font-semibold text-indigo-950 dark:text-indigo-100">Módulo listo para configurar</h3>
                    <p class="mt-2 text-sm text-indigo-700 dark:text-indigo-300">Aquí se agregarán próximamente los registros y acciones de despacho.</p>
                </div>
            </div>
        </div>
    </section>
@endsection
