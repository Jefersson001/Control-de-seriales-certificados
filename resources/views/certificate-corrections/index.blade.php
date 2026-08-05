@extends('layouts.dashboard')

@section('title', 'Corrección Maestro Seriales Certificados')
@section('description', 'Módulo de corrección del Maestro Seriales Certificados.')
@section('page-heading', 'Corrección Maestro Seriales Certificados')

@section('content')
    <section class="mx-auto max-w-6xl">
        <div class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-8 shadow-sm dark:border-white/10 dark:bg-white/[0.04] sm:p-10">
            <div class="absolute -right-20 -top-20 size-64 rounded-full bg-amber-500/10 blur-3xl" aria-hidden="true"></div>

            <div class="relative max-w-2xl">
                <span class="mb-6 grid size-12 place-items-center rounded-2xl bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6.75 17.25 10.5M4.5 19.5l3.621-.724a2.25 2.25 0 0 0 1.159-.616l9.19-9.19a2.652 2.652 0 0 0-3.75-3.75l-9.19 9.19a2.25 2.25 0 0 0-.616 1.159L4.5 19.5Z"/>
                    </svg>
                </span>

                <p class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-amber-600 dark:text-amber-300">Nuevo módulo</p>
                <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                    Corrección Maestro Seriales Certificados
                </h2>
                <p class="mt-5 text-lg leading-8 text-slate-600 dark:text-slate-300">
                    Este espacio está preparado para incorporar el proceso de consulta y corrección de los registros del maestro.
                </p>
            </div>
        </div>
    </section>
@endsection
