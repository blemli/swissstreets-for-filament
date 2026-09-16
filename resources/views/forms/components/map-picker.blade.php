@php
    $config = [
        ...$getMapConfig(),
        'center' => $getCenter(),
        'zoom' => $getZoom(),
    ];
    $childSchema = $getChildSchema();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="swissstreetsMapPicker({
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

    @once
        <style>
            /* Own stacking context: Leaflet's z-indexes must not cover the search dropdown. */
            .fi-fo-map-picker-map { position: relative; z-index: 0; isolation: isolate; }
            .fi-fo-map-picker-search { position: relative; z-index: 1; }
            .fi-fo-map-picker-map .leaflet-container { font: inherit; background: transparent; }
            .fi-fo-map-picker-pin { background: none; border: 0; }
            /* Filament's raw --primary-* variables: Tailwind v4 tree-shakes the --color-* aliases it does not see used. */
            .fi-fo-map-picker-pin svg { fill: var(--primary-600); filter: drop-shadow(0 6px 5px rgba(0,0,0,.45)); }
            .dark .fi-fo-map-picker-pin svg { fill: var(--primary-400); filter: drop-shadow(0 6px 6px rgba(0,0,0,.7)); }
            .fi-fo-map-picker-map .leaflet-control-attribution { font-size: 0.65rem; }
            /* Dark mode: keep the tiles legible instead of blinding. */
            .dark .fi-fo-map-picker-map .leaflet-tile-pane { filter: invert(1) hue-rotate(180deg) brightness(0.85) contrast(0.9) saturate(0.4); }
            .dark .fi-fo-map-picker-map .leaflet-control-zoom a,
            .dark .fi-fo-map-picker-map .leaflet-control-attribution { background: #18181b; color: #d4d4d8; }
            .dark .fi-fo-map-picker-map .leaflet-control-attribution a { color: #a1a1aa; }
            .dark .fi-fo-map-picker-map .leaflet-bar { border-color: rgba(255,255,255,.2); }
        </style>

        <script>
            // Plain factory on window: registered before Alpine initialises
            // the element, re-runnable on wire:navigate, no build step.
            window.swissstreetsMapPicker = function ({ state, config }) {
                return {
                    state,
                    config,
                    map: null,
                    marker: null,

                    async init() {
                        await this.loadLeaflet()

                        const L = window.L
                        const start = this.hasPoint() ? [this.state.lat, this.state.lng] : this.config.center

                        this.map = L.map(this.$refs.map, { zoomControl: true, attributionControl: true })
                            .setView(start, this.hasPoint() ? 16 : this.config.zoom)

                        L.tileLayer(this.config.tiles, { attribution: this.config.attribution, maxZoom: 19 }).addTo(this.map)

                        if (this.hasPoint()) {
                            this.placeMarker(this.state.lat, this.state.lng)
                        }

                        this.map.on('click', (event) => this.pick(event.latlng.lat, event.latlng.lng))

                        this.$watch('state', () => {
                            if (! this.hasPoint()) {
                                this.marker?.remove()
                                this.marker = null

                                return
                            }

                            const current = this.marker?.getLatLng()

                            if (current && Math.abs(current.lat - this.state.lat) < 1e-7 && Math.abs(current.lng - this.state.lng) < 1e-7) {
                                return
                            }

                            this.placeMarker(this.state.lat, this.state.lng)
                            this.map.setView([this.state.lat, this.state.lng], Math.max(this.map.getZoom(), 16))
                        })

                        setTimeout(() => this.map.invalidateSize(), 250)
                    },

                    hasPoint() {
                        const filled = (value) => value !== null && value !== undefined && value !== ''

                        return !! this.state && filled(this.state.lat) && filled(this.state.lng)
                    },

                    placeMarker(lat, lng) {
                        if (this.marker) {
                            this.marker.setLatLng([lat, lng])

                            return
                        }

                        this.marker = window.L.marker([lat, lng], { draggable: true, icon: this.pinIcon() }).addTo(this.map)
                        this.marker.on('dragend', () => {
                            const position = this.marker.getLatLng()
                            this.pick(position.lat, position.lng)
                        })
                    },

                    // A free pin is not a register address any more.
                    pick(lat, lng) {
                        this.state = { ...this.state, lat: Math.round(lat * 1e7) / 1e7, lng: Math.round(lng * 1e7) / 1e7, search: null }
                    },

                    // Heroicon map-pin in the panel's primary colour instead of Leaflet's blue PNG.
                    pinIcon() {
                        return window.L.divIcon({
                            className: 'fi-fo-map-picker-pin',
                            html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="56" height="56"><path fill-rule="evenodd" d="m11.54 22.351.07.04.028.016a.76.76 0 0 0 .723 0l.028-.015.071-.041a16.975 16.975 0 0 0 1.144-.742 19.58 19.58 0 0 0 2.683-2.282c1.944-1.99 3.963-4.98 3.963-8.827a8.25 8.25 0 0 0-16.5 0c0 3.846 2.02 6.837 3.963 8.827a19.58 19.58 0 0 0 2.682 2.282 16.975 16.975 0 0 0 1.145.742ZM12 13.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/></svg>',
                            iconSize: [56, 56],
                            iconAnchor: [28, 53],
                        })
                    },

                    clear() {
                        this.state = { ...this.state, lat: null, lng: null, search: null }
                    },

                    loadLeaflet() {
                        if (window.L) {
                            return Promise.resolve()
                        }

                        if (! document.querySelector('link[data-swissstreets-leaflet]')) {
                            const link = document.createElement('link')
                            link.rel = 'stylesheet'
                            link.href = this.config.leafletCss
                            link.dataset.swissstreetsLeaflet = '1'
                            document.head.appendChild(link)
                        }

                        if (! window.__swissstreetsLeaflet) {
                            window.__swissstreetsLeaflet = new Promise((resolve, reject) => {
                                const script = document.createElement('script')
                                script.src = this.config.leafletJs
                                script.onload = resolve
                                script.onerror = reject
                                document.head.appendChild(script)
                            })
                        }

                        return window.__swissstreetsLeaflet
                    },
                }
            }
        </script>
    @endonce
</x-dynamic-component>
