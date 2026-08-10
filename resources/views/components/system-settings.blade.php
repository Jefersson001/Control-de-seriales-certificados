<?php

use App\Models\SystemSetting;
use App\UserPermission;
use Livewire\Component;

new class extends Component
{
    public int $livewirePayloadMaxMb = 1;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewSystemSettings), 403);

        $this->livewirePayloadMaxMb = SystemSetting::livewirePayloadMaxMb();
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::EditSystemSettings), 403);

        $validated = $this->validate([
            'livewirePayloadMaxMb' => ['required', 'integer', 'min:1', 'max:39'],
        ], [
            'livewirePayloadMaxMb.required' => 'Indica el tamaño máximo permitido.',
            'livewirePayloadMaxMb.integer' => 'La cantidad debe ser un número entero.',
            'livewirePayloadMaxMb.min' => 'El límite mínimo es 1 MB.',
            'livewirePayloadMaxMb.max' => 'El límite máximo permitido por este servidor es 39 MB.',
        ]);

        SystemSetting::query()->updateOrCreate(
            ['key' => SystemSetting::LIVEWIRE_PAYLOAD_MAX_MB],
            ['value' => (string) $validated['livewirePayloadMaxMb']],
        );

        config([
            'livewire.payload.max_size' => $validated['livewirePayloadMaxMb'] * 1024 * 1024,
        ]);

        session()->flash('status', 'Parámetros del sistema actualizados correctamente.');
    }
};
?>

<div>
    @if (session('status'))
        <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="border-b border-slate-200 bg-slate-50/70 px-6 py-6 dark:border-white/10 dark:bg-slate-950/30 sm:px-8">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Configuración</p>
            <h2 class="mt-3 text-2xl font-semibold">Límites del sistema</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-400">
                Controla el tamaño máximo que puede enviar una petición de Livewire. El valor original y predeterminado del sistema es 1 MB.
            </p>
        </div>

        <div class="p-6 sm:p-8">
            <div class="max-w-xl">
                <label for="livewire-payload-max-mb" class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-200">
                    Tamaño máximo de petición Livewire
                </label>
                <div class="relative">
                    <input
                        id="livewire-payload-max-mb"
                        wire:model="livewirePayloadMaxMb"
                        type="number"
                        min="1"
                        max="39"
                        step="1"
                        @disabled(! auth()->user()->hasPermission(UserPermission::EditSystemSettings))
                        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 pr-16 text-slate-950 outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-white/10 dark:bg-slate-950/40 dark:text-white dark:disabled:bg-white/5"
                    >
                    <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm font-semibold text-slate-500">MB</span>
                </div>
                @error('livewirePayloadMaxMb')
                    <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p>
                @enderror
                <p class="mt-3 text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Rango permitido: 1 a 39 MB. Aumentar este valor permite solicitudes más grandes, pero también incrementa el consumo de memoria y tiempo de procesamiento.
                </p>
            </div>

            @if (auth()->user()->hasPermission(UserPermission::EditSystemSettings))
                <div class="mt-8 border-t border-slate-200 pt-6 dark:border-white/10">
                    <button wire:click="save" wire:loading.attr="disabled" type="button" class="rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-500 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="save">Guardar configuración</span>
                        <span wire:loading wire:target="save">Guardando...</span>
                    </button>
                </div>
            @endif
        </div>
    </section>
</div>
