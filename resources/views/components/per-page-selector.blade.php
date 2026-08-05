@props(['id'])

<div class="w-full sm:w-auto">
    <label for="{{ $id }}" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
        Registros por página
    </label>
    <input
        id="{{ $id }}"
        wire:model.live.debounce.500ms="perPage"
        type="number"
        min="1"
        max="10000"
        step="1"
        inputmode="numeric"
        aria-label="Cantidad de registros por página"
        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white sm:w-32"
    >
</div>
