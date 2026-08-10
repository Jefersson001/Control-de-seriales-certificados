@extends('layouts.dashboard')

@section('title', 'Devoluciones')
@section('description', 'Gestión de devoluciones del sistema.')
@section('page-heading', 'Devoluciones')

@section('content')
    <section class="mx-auto max-w-6xl">
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
            <div class="border-b border-slate-200 bg-slate-50/70 px-6 py-6 dark:border-white/10 dark:bg-slate-950/30 sm:px-8">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-600 dark:text-amber-300">Despacho</p>
                <h2 class="mt-3 text-2xl font-semibold">Gestión de devoluciones</h2>
                <p class="mt-2 max-w-3xl leading-7 text-slate-600 dark:text-slate-400">
                    Este módulo está preparado para incorporar el flujo, los campos y las operaciones de devolución.
                </p>
            </div>
            <div class="p-6 sm:p-8">
                <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50/60 p-8 text-center dark:border-amber-500/30 dark:bg-amber-500/10">
                    <h3 class="text-lg font-semibold text-amber-950 dark:text-amber-100">Módulo listo para configurar</h3>
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">Aquí se agregarán próximamente los registros y acciones de devolución.</p>
                </div>
            </div>
        </div>
    </section>
@endsection
