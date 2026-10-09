@props(['field'])

<div class="flex flex-wrap items-end gap-4 border-b border-slate-200 p-5 dark:border-white/10">
    <div class="w-full sm:w-auto">
        <label for="certificate-date-field" class="mb-2 block text-sm font-semibold">Filtrar por fecha</label>
        <select id="certificate-date-field" wire:model.live="dateField" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950 dark:text-white">
            <option value="">Todas las fechas</option>
            <option value="created_at">Fecha de creación</option>
            <option value="request_date">Fecha de la solicitud de certificación</option>
            <option value="issued_on">Fecha de emisión</option>
        </select>
        @error('dateField') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
    @if ($field !== '')
        <div>
            <label for="certificate-date-from" class="mb-2 block text-sm font-semibold">Desde</label>
            <input id="certificate-date-from" wire:model.live="dateFrom" type="date" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950 dark:text-white">
            @error('dateFrom') <p class="mt-2 text-sm text-red-600">Ingresa una fecha válida.</p> @enderror
        </div>
        <div>
            <label for="certificate-date-to" class="mb-2 block text-sm font-semibold">Hasta</label>
            <input id="certificate-date-to" wire:model.live="dateTo" type="date" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950 dark:text-white">
            @error('dateTo') <p class="mt-2 text-sm text-red-600">Hasta debe ser una fecha válida igual o posterior a Desde.</p> @enderror
        </div>
    @endif
</div>
