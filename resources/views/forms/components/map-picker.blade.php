@php
    $config = [
        ...$getMapConfig(),
        'center' => $getCenter(),
        'zoom' => $getZoom(),
    ];
    $childSchema = $getChildSchema();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- Filament's async Alpine component: the module (Leaflet bundled) and the
         stylesheets load when the field becomes visible — on the page, in a
         modal a Livewire morph inserted, in a section that opens. --}}
    <div
        x-load="visible"
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('map-picker', 'blemli/swissstreets-for-filament') }}"
        x-load-css="[
            @js(\Filament\Support\Facades\FilamentAsset::getStyleHref('leaflet', 'blemli/swissstreets-for-filament')),
            @js(\Filament\Support\Facades\FilamentAsset::getStyleHref('map-picker', 'blemli/swissstreets-for-filament')),
        ]"
        x-data="mapPicker({
            state: $wire.$entangle('{{ $getStatePath() }}'),
            config: JSON.parse($el.dataset.config),
        })"
        data-config="{{ json_encode($config) }}"
        class="fi-fo-map-picker flex flex-col gap-y-3"
    >
        @if ($childSchema)
            <div class="fi-fo-map-picker-search">
                {{ $childSchema }}
            </div>
        @endif

        <div
            wire:ignore
            class="fi-fo-map-picker-map overflow-hidden rounded-lg bg-gray-50 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20"
            style="height: {{ $getHeight() }}"
        >
            <div x-ref="map" class="h-full w-full" style="height: 100%"></div>
        </div>

        <div class="flex items-center justify-between gap-x-3 text-xs text-gray-500 dark:text-gray-400">
            <span x-text="hasPoint() ? `${Number(state.lat).toFixed(5)}, ${Number(state.lng).toFixed(5)}` : @js(__('swissstreets-for-filament::swissstreets.map_picker.hint'))"></span>

            <button
                type="button"
                x-show="hasPoint()"
                x-on:click="clear()"
                class="fi-link fi-size-sm text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ __('swissstreets-for-filament::swissstreets.map_picker.clear') }}
            </button>
        </div>
    </div>
</x-dynamic-component>
