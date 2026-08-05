<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ config('app.name') }}">

        <title>Inicio | {{ config('app.name') }}</title>

        <script>
            const selectedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            document.documentElement.classList.toggle('dark', selectedTheme === 'dark' || (! selectedTheme && prefersDark));
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-950 antialiased transition-colors duration-300 dark:bg-slate-950 dark:text-white">
        <div class="flex min-h-screen flex-col">
            <header class="border-b border-slate-200 bg-white/80 backdrop-blur-xl transition-colors duration-300 dark:border-white/10 dark:bg-slate-950/80">
                <nav class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-6 py-5" aria-label="Navegación principal">
                    <a href="{{ route('home') }}" class="flex items-center gap-3 font-semibold tracking-tight">
                        <span class="grid size-10 place-items-center rounded-xl bg-indigo-500 shadow-lg shadow-indigo-500/25">
                            C
                        </span>
                        <span class="hidden lg:inline">{{ config('app.name') }}</span>
                    </a>

                    <div class="flex items-center gap-1 text-sm font-medium sm:gap-2">
                        <a href="{{ route('home') }}" class="rounded-full bg-slate-100 px-4 py-2.5 text-slate-950 dark:bg-white/10 dark:text-white sm:px-5">
                            Inicio
                        </a>
                        <a href="{{ route('login') }}" class="rounded-full px-4 py-2.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white sm:px-5">
                            Iniciar sesión
                        </a>
                        <button type="button" data-theme-toggle class="grid size-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-600 transition hover:border-indigo-300 hover:text-indigo-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-indigo-400 dark:hover:text-white">
                            <svg class="size-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5M12 19.5V21M21 12h-1.5M4.5 12H3m15.364-6.364-1.061 1.061M6.697 17.303l-1.061 1.061m12.728 0-1.061-1.061M6.697 6.697 5.636 5.636M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"/>
                            </svg>
                            <svg class="hidden size-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/>
                            </svg>
                        </button>
                    </div>
                </nav>
            </header>

            <main class="relative isolate flex grow items-center overflow-hidden">
                <div class="absolute left-1/2 top-1/2 -z-10 size-96 -translate-x-1/2 -translate-y-1/2 rounded-full bg-indigo-600/20 blur-3xl" aria-hidden="true"></div>
                <div class="absolute right-0 top-0 -z-10 size-72 rounded-full bg-cyan-500/10 blur-3xl" aria-hidden="true"></div>

                <section class="mx-auto w-full max-w-6xl px-6 py-24">
                    <div class="max-w-3xl">
                        <p class="mb-5 text-sm font-semibold uppercase tracking-[0.25em] text-indigo-600 dark:text-indigo-300">
                            Bienvenido
                        </p>
                        <h1 class="text-5xl font-semibold tracking-tight text-balance sm:text-7xl">
                            El inicio de una gran idea.
                        </h1>
                        <p class="mt-7 max-w-2xl text-lg leading-8 text-slate-600 dark:text-slate-300">
                            Esta es la página principal de tu proyecto. Desde aquí podrás presentar tu plataforma y permitir que los usuarios autorizados ingresen al sistema.
                        </p>

                        <div class="mt-10 flex flex-wrap gap-4">
                            <a href="{{ route('login') }}" class="rounded-full bg-indigo-500 px-6 py-3 font-semibold shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-400">
                                Iniciar sesión
                            </a>
                            <span class="flex items-center px-2 text-sm text-slate-500 dark:text-slate-400">
                                Acceso exclusivo para usuarios autorizados
                            </span>
                        </div>
                    </div>
                </section>
            </main>

            <footer class="border-t border-slate-200 dark:border-white/10">
                <div class="mx-auto max-w-6xl px-6 py-6 text-sm text-slate-500 dark:text-slate-500">
                    &copy; {{ now()->year }} {{ config('app.name') }}.
                </div>
            </footer>
        </div>
    </body>
</html>
