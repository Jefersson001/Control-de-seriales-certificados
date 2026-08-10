<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="@yield('description', 'Panel principal del sistema.')">

        <title>@yield('title', 'Panel') | {{ config('app.name') }}</title>

        <script>
            const selectedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            document.documentElement.classList.toggle('dark', selectedTheme === 'dark' || (! selectedTheme && prefersDark));
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased transition-colors duration-300 dark:bg-slate-950 dark:text-white">
        <div data-sidebar-overlay class="fixed inset-0 z-30 hidden bg-slate-950/60 backdrop-blur-sm lg:hidden" aria-hidden="true"></div>

        <aside id="dashboard-sidebar" data-sidebar class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-x-hidden overflow-y-auto border-r border-slate-200 bg-white transition-transform duration-300 ease-out lg:translate-x-0 dark:border-white/10 dark:bg-slate-900">
            <a href="{{ route('dashboard') }}" class="flex h-20 shrink-0 items-center gap-3 border-b border-slate-200 px-6 dark:border-white/10">
                <span class="grid size-10 place-items-center rounded-xl bg-indigo-500 font-semibold text-white shadow-lg shadow-indigo-500/25">
                    C
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold leading-tight tracking-tight">{{ config('app.name') }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Panel administrativo</p>
                </div>
            </a>

            <div class="flex min-h-fit grow flex-col px-4 py-6">
                <p class="px-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">
                    Menú
                </p>

                <nav data-sidebar-menu class="mt-4 flex flex-col gap-2 pr-1" aria-label="Navegación del sistema">
                    @if (auth()->user()->hasPermission(App\UserPermission::ViewCertificates))
                        <a
                            href="{{ route('certificates.index') }}"
                            @class([
                                'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('certificates.*'),
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('certificates.*'),
                            ])
                        >
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M12 3l1.758 1.758 2.485-.243.243 2.485L18.243 8.75 16.485 10.5l-.243 2.485-2.485-.243-.243-2.485L12 14.5l-1.758-1.758-2.485.243-.243-2.485L5.757 8.75 7.515 7l.243-2.485 2.485.243L12 3Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 13.75 7.5 21l4.5-2.25L16.5 21l-.75-7.25"/>
                            </svg>
                            <span>Maestro Seriales Certificados</span>
                        </a>
                    @endif

                    @if (
                        auth()->user()->hasPermission(App\UserPermission::ViewUsers)
                        || auth()->user()->hasPermission(App\UserPermission::ViewProducts)
                        || auth()->user()->hasPermission(App\UserPermission::ViewSystemSettings)
                    )
                        <details
                            data-configuration-menu
                            class="group order-[999]"
                            @if (request()->routeIs('users.*', 'products.*', 'system_settings.*')) open @endif
                        >
                            <summary
                                @class([
                                    'flex cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition [&::-webkit-details-marker]:hidden',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('users.*', 'products.*', 'system_settings.*'),
                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('users.*', 'products.*', 'system_settings.*'),
                                ])
                            >
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.75h4.5l.75 2.25 2.25.75 2.25-.75 2.25 3.75-1.5 1.75v2.5l1.5 1.75-2.25 3.75-2.25-.75-2.25.75-.75 2.25h-4.5L9 19.5l-2.25-.75-2.25.75-2.25-3.75L3.75 14v-2.5L2.25 9.75 4.5 6l2.25.75L9 6l.75-2.25Z"/>
                                    <circle cx="12" cy="12.75" r="3"/>
                                </svg>
                                <span class="grow">Configuración</span>
                                <svg class="size-4 shrink-0 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                                </svg>
                            </summary>

                            <div class="ml-6 mt-2 flex flex-col gap-1 border-l border-slate-200 pl-3 dark:border-white/10">
                                @if (auth()->user()->hasPermission(App\UserPermission::ViewUsers))
                                    <a
                                        href="{{ route('users.index') }}"
                                        @class([
                                            'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('users.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('users.*'),
                                        ])
                                    >
                                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m1.5-1.5h-3M13.5 6A3.75 3.75 0 1 1 6 6a3.75 3.75 0 0 1 7.5 0ZM3 20.25a6.75 6.75 0 0 1 13.5 0"/>
                                        </svg>
                                        Usuarios
                                    </a>
                                @endif

                                @if (auth()->user()->hasPermission(App\UserPermission::ViewProducts))
                                    <a
                                        href="{{ route('products.index') }}"
                                        @class([
                                            'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('products.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('products.*'),
                                        ])
                                    >
                                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8.25 4.5L12 12 3.75 7.5 12 3Z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5V16.5L12 21m0-9v9m8.25-13.5V16.5L12 21"/>
                                        </svg>
                                        Productos
                                    </a>
                                @endif

                                @if (auth()->user()->hasPermission(App\UserPermission::ViewSystemSettings))
                                    <a
                                        href="{{ route('system_settings.index') }}"
                                        @class([
                                            'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('system_settings.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('system_settings.*'),
                                        ])
                                    >
                                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v3m0 12v3M3 12h3m12 0h3M5.64 5.64l2.12 2.12m8.48 8.48 2.12 2.12m0-12.72-2.12 2.12M7.76 16.24l-2.12 2.12"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        Parámetros del sistema
                                    </a>
                                @endif
                            </div>
                        </details>
                    @endif

                    @if (
                        auth()->user()->hasPermission(App\UserPermission::ViewMotorcycleSerialRequests)
                        || auth()->user()->hasPermission(App\UserPermission::ViewVehicleIdentificationRecordManagement)
                    )
                        <details
                            data-requests-menu
                            class="group order-[998]"
                            @if (request()->routeIs('motorcycle_serial_requests.*', 'vehicle_identification_record_management.*')) open @endif
                        >
                            <summary
                                @class([
                                    'flex cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition [&::-webkit-details-marker]:hidden',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('motorcycle_serial_requests.*', 'vehicle_identification_record_management.*'),
                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('motorcycle_serial_requests.*', 'vehicle_identification_record_management.*'),
                                ])
                            >
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75h9A2.25 2.25 0 0 1 18.75 6v12A2.25 2.25 0 0 1 16.5 20.25h-9A2.25 2.25 0 0 1 5.25 18V6A2.25 2.25 0 0 1 7.5 3.75Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 8.25h7.5M8.25 12h7.5m-7.5 3.75h4.5"/>
                                </svg>
                                <span class="grow">Solicitudes</span>
                                <svg class="size-4 shrink-0 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                                </svg>
                            </summary>

                            <div class="ml-6 mt-2 flex flex-col gap-1 border-l border-slate-200 pl-3 dark:border-white/10">
                                @if (auth()->user()->hasPermission(App\UserPermission::ViewMotorcycleSerialRequests))
                                    <a
                                        href="{{ route('motorcycle_serial_requests.index') }}"
                                        @class([
                                            'flex items-start gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('motorcycle_serial_requests.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('motorcycle_serial_requests.*'),
                                        ])
                                    >
                                        <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <circle cx="6.75" cy="17.25" r="3"/>
                                            <circle cx="17.25" cy="17.25" r="3"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17.25h4.5l-3-6h3.75l2.25 6M6.75 17.25l3.75-7.5h3M15.75 6.75h2.25"/>
                                        </svg>
                                        <span class="leading-5">Solicitud de seriales de motos</span>
                                    </a>
                                @endif

                                @if (auth()->user()->hasPermission(App\UserPermission::ViewVehicleIdentificationRecordManagement))
                                    <a
                                        href="{{ route('vehicle_identification_record_management.index') }}"
                                        @class([
                                            'flex items-start gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('vehicle_identification_record_management.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('vehicle_identification_record_management.*'),
                                        ])
                                    >
                                        <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.75h4.5l.75 2.25 2.25.75 2.25-.75 2.25 3.75-1.5 1.75v2.5l1.5 1.75-2.25 3.75-2.25-.75-2.25.75-.75 2.25h-4.5L9 19.5l-2.25-.75-2.25.75-2.25-3.75L3.75 14v-2.5L2.25 9.75 4.5 6l2.25.75L9 6l.75-2.25Z"/>
                                            <circle cx="12" cy="12.75" r="3"/>
                                        </svg>
                                        <span class="leading-5">Gestión de Constancia de Registro de Número de Identificación de Vehículo</span>
                                    </a>
                                @endif
                            </div>
                        </details>
                    @endif

                    @if (
                        auth()->user()->hasPermission(App\UserPermission::ViewDispatches)
                        || auth()->user()->hasPermission(App\UserPermission::ViewReturns)
                    )
                        <details
                            data-dispatch-menu
                            class="group order-[998]"
                            @if (request()->routeIs('dispatches.*', 'returns.*')) open @endif
                        >
                            <summary
                                @class([
                                    'flex cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition [&::-webkit-details-marker]:hidden',
                                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('dispatches.*', 'returns.*'),
                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('dispatches.*', 'returns.*'),
                                ])
                            >
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h10.5v9H3.75v-9Zm10.5 3h3l3 3v3h-6v-6Z"/>
                                    <circle cx="7.5" cy="18" r="2.25"/>
                                    <circle cx="17.25" cy="18" r="2.25"/>
                                </svg>
                                <span class="grow">Despacho</span>
                                <svg class="size-4 shrink-0 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                                </svg>
                            </summary>

                            <div class="ml-6 mt-2 flex flex-col gap-1 border-l border-slate-200 pl-3 dark:border-white/10">
                                @if (auth()->user()->hasPermission(App\UserPermission::ViewDispatches))
                                    <a
                                        href="{{ route('dispatches.index') }}"
                                        @class([
                                            'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('dispatches.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('dispatches.*'),
                                        ])
                                    >
                                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 5.25h15v13.5h-15V5.25Zm3 3h9m-9 3.75h9m-9 3.75h5.25"/>
                                        </svg>
                                        Despacho
                                    </a>
                                @endif

                                @if (auth()->user()->hasPermission(App\UserPermission::ViewReturns))
                                    <a
                                        href="{{ route('returns.index') }}"
                                        @class([
                                            'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition',
                                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('returns.*'),
                                            'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('returns.*'),
                                        ])
                                    >
                                        <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 7.5-4.5 4.5L9 16.5M4.5 12h9a6 6 0 0 1 6 6"/>
                                        </svg>
                                        Devoluciones
                                    </a>
                                @endif
                            </div>
                        </details>
                    @endif

                    @if (auth()->user()->hasPermission(App\UserPermission::ViewVehicleIdentificationRecord))
                        <a
                            href="{{ route('vehicle_identification_records.index') }}"
                            @class([
                                'flex items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium transition',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' => request()->routeIs('vehicle_identification_records.*'),
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' => ! request()->routeIs('vehicle_identification_records.*'),
                            ])
                        >
                            <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75h9A2.25 2.25 0 0 1 18.75 6v12A2.25 2.25 0 0 1 16.5 20.25h-9A2.25 2.25 0 0 1 5.25 18V6A2.25 2.25 0 0 1 7.5 3.75Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 8.25h7.5M8.25 12h7.5m-7.5 3.75h4.5"/>
                            </svg>
                            <span class="leading-5">Constancia de Registro de Número de Identificación de Vehículo</span>
                        </a>
                    @endif

                </nav>

                <div data-sidebar-footer class="mt-4 shrink-0 border-t border-slate-200 pt-4 dark:border-white/10">
                    <div class="mb-4 flex items-center gap-3 rounded-2xl bg-slate-100 p-3 dark:bg-white/5">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-indigo-100 font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                            {{ auth()->user()->initials() }}
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                                    {{ auth()->user()->role->label() }}
                                </span>
                            </div>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-rose-600 transition hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-500/10">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3-6 3 3m0 0-3 3m3-3H9"/>
                            </svg>
                            Cerrar sesión
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div data-dashboard-content class="min-h-screen transition-[padding] duration-300 lg:pl-72">
            <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/80 backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/80">
                <div class="flex h-20 items-center justify-between gap-4 px-6 lg:px-8">
                    <div class="flex items-center gap-4">
                        <button type="button" data-sidebar-toggle aria-controls="dashboard-sidebar" aria-expanded="true" class="grid size-11 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-indigo-400 dark:hover:text-white">
                            <span class="sr-only">Abrir o cerrar menú</span>
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>

                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">Sistema</p>
                            <h1 class="text-lg font-semibold">@yield('page-heading', 'Panel principal')</h1>
                        </div>
                    </div>

                    <button type="button" data-theme-toggle class="grid size-11 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-indigo-400 dark:hover:text-white">
                        <svg class="size-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5M12 19.5V21M21 12h-1.5M4.5 12H3m15.364-6.364-1.061 1.061M6.697 17.303l-1.061 1.061m12.728 0-1.061-1.061M6.697 6.697 5.636 5.636M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"/>
                        </svg>
                        <svg class="hidden size-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/>
                        </svg>
                    </button>
                </div>
            </header>

            <main class="px-6 py-10 lg:px-8">
                @yield('content')
            </main>
        </div>

        @livewireScripts
    </body>
</html>
