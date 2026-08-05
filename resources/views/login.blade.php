<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Acceso de usuarios autorizados.">

        <title>Iniciar sesión | {{ config('app.name') }}</title>

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
                        <a href="{{ route('home') }}" class="rounded-full px-4 py-2.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white sm:px-5">
                            Inicio
                        </a>
                        <a href="{{ route('login') }}" class="rounded-full bg-slate-100 px-4 py-2.5 text-slate-950 dark:bg-white/10 dark:text-white sm:px-5">
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

            <main class="relative isolate grid grow place-items-center overflow-hidden px-6 py-16">
                <div class="absolute left-1/4 top-1/3 -z-10 size-80 rounded-full bg-indigo-600/25 blur-3xl" aria-hidden="true"></div>
                <div class="absolute bottom-0 right-1/4 -z-10 size-72 rounded-full bg-cyan-500/15 blur-3xl" aria-hidden="true"></div>

                <section class="w-full max-w-md rounded-3xl border border-slate-200 bg-white/90 p-8 shadow-2xl shadow-slate-300/50 backdrop-blur-xl transition-colors duration-300 dark:border-white/10 dark:bg-white/[0.07] dark:shadow-black/40 sm:p-10">
                    <div class="mb-8">
                        <span class="mb-6 grid size-12 place-items-center rounded-2xl bg-indigo-500 shadow-lg shadow-indigo-500/25">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6.75V5.625a3.375 3.375 0 0 0-6.75 0V6.75m-.75 0h7.5A2.25 2.25 0 0 1 18 9v9.75A2.25 2.25 0 0 1 15.75 21h-7.5A2.25 2.25 0 0 1 6 18.75V9a2.25 2.25 0 0 1 2.25-2.25Z"/>
                            </svg>
                        </span>
                        <h1 class="text-3xl font-semibold tracking-tight">Bienvenido de nuevo</h1>
                        <p class="mt-3 leading-7 text-slate-600 dark:text-slate-400">
                            Ingresa tus credenciales para acceder al sistema.
                        </p>
                    </div>

                    @if (session('status'))
                        <div role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div role="alert" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.authenticate') }}" class="flex flex-col gap-6">
                        @csrf

                        <div>
                            <label for="email" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">
                                Correo electrónico
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autocomplete="username"
                                placeholder="nombre@ejemplo.com"
                                value="{{ old('email') }}"
                                required
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white dark:placeholder:text-slate-600"
                            >
                        </div>

                        <div>
                            <label for="password" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">
                                Contraseña
                            </label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Ingresa tu contraseña"
                                required
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white dark:placeholder:text-slate-600"
                            >
                        </div>

                        <label class="flex cursor-pointer items-center gap-3 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 bg-white text-indigo-500 focus:ring-indigo-500/30 dark:border-white/20 dark:bg-slate-950">
                            Mantener mi sesión iniciada
                        </label>

                        <button type="submit" class="w-full rounded-xl bg-indigo-500 px-5 py-3.5 font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-500/30">
                            Ingresar
                        </button>
                    </form>

                    <div class="mt-8 border-t border-slate-200 pt-6 text-center text-sm text-slate-500 dark:border-white/10">
                        Acceso exclusivo para usuarios autorizados.
                    </div>
                </section>
            </main>

            <footer class="border-t border-slate-200 dark:border-white/10">
                <div class="mx-auto max-w-6xl px-6 py-6 text-center text-sm text-slate-500">
                    &copy; {{ now()->year }} {{ config('app.name') }}.
                </div>
            </footer>
        </div>
    </body>
</html>
