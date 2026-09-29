import L from 'leaflet'

// The map picker as an Alpine component, loaded by Filament's `x-load` when
// the field becomes visible — in the initial page, in a modal that arrives
// through a Livewire morph, in a collapsed section. Leaflet is bundled in:
// no CDN round trip, works offline like the register itself.
export default function mapPicker({ state, config }) {
    return {
        state,
        config,
        map: null,
        marker: null,

        init() {
            const start = this.hasPoint() ? [this.state.lat, this.state.lng] : this.config.center

            this.map = L.map(this.$refs.map, { zoomControl: true, attributionControl: false })
                .setView(start, this.hasPoint() ? 16 : this.config.zoom)

            // The tile source reference only, no Leaflet prefix — it is a form field, not a map site.
            L.control.attribution({ prefix: false }).addTo(this.map)

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

            // A modal transitions in, a section opens, the window resizes:
            // the map re-measures its box instead of guessing with a timer.
            new ResizeObserver(() => this.map?.invalidateSize()).observe(this.$refs.map)
            this.$nextTick(() => this.map.invalidateSize())
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

            this.marker = L.marker([lat, lng], { draggable: true, icon: this.pinIcon() }).addTo(this.map)
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
            return L.divIcon({
                className: 'fi-fo-map-picker-pin',
                html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="56" height="56"><path fill-rule="evenodd" d="m11.54 22.351.07.04.028.016a.76.76 0 0 0 .723 0l.028-.015.071-.041a16.975 16.975 0 0 0 1.144-.742 19.58 19.58 0 0 0 2.683-2.282c1.944-1.99 3.963-4.98 3.963-8.827a8.25 8.25 0 0 0-16.5 0c0 3.846 2.02 6.837 3.963 8.827a19.58 19.58 0 0 0 2.682 2.282 16.975 16.975 0 0 0 1.145.742ZM12 13.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/></svg>',
                iconSize: [56, 56],
                iconAnchor: [28, 53],
            })
        },

        clear() {
            this.state = { ...this.state, lat: null, lng: null, search: null }
        },
    }
}
