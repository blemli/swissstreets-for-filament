import { build } from 'esbuild'
import { copyFileSync, mkdirSync } from 'node:fs'

// The map picker as a Filament AlpineComponent (ES module, Leaflet bundled in),
// its stylesheet, and Leaflet's own stylesheet — all published by
// `php artisan filament:assets` in the consuming app.
mkdirSync('resources/dist', { recursive: true })

await build({
    entryPoints: ['resources/js/map-picker.js'],
    bundle: true,
    format: 'esm',
    minify: true,
    target: ['es2020'],
    outfile: 'resources/dist/map-picker.js',
})

copyFileSync('node_modules/leaflet/dist/leaflet.css', 'resources/dist/leaflet.css')
copyFileSync('resources/css/map-picker.css', 'resources/dist/map-picker.css')
