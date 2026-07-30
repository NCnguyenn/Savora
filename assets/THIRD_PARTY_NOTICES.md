# Third-party visual assets

## Font Awesome Free 6.4.0

- CSS source: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css`
- Solid font source: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2`
- Regular font source: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.woff2`
- Upstream project: `https://github.com/FortAwesome/Font-Awesome/tree/6.4.0`
- License: icons CC BY 4.0, fonts SIL OFL 1.1, code MIT. The upstream `LICENSE.txt` is stored at `assets/vendor/fontawesome/LICENSE.txt`.

## Leaflet 1.9.4

- CSS source: `https://unpkg.com/leaflet@1.9.4/dist/leaflet.css`
- JavaScript source: `https://unpkg.com/leaflet@1.9.4/dist/leaflet.js`
- Image sources: `https://unpkg.com/leaflet@1.9.4/dist/images/{layers.png,layers-2x.png,marker-icon.png,marker-icon-2x.png,marker-shadow.png}`
- Upstream project: `https://github.com/Leaflet/Leaflet/tree/v1.9.4`
- License: BSD 2-Clause. The upstream license is stored at `assets/vendor/leaflet/LICENSE`.

The map library is local. CARTO/OpenStreetMap raster tiles remain remote and optional; Savora exposes a visible local degraded state when those tiles are unavailable.

## Unsplash catalog photographs

The four photographs are stored locally at 800px width and are used under the Unsplash License (`https://unsplash.com/license`). Exact download URLs:

- Mega Burger Feast Combo: `https://images.unsplash.com/photo-1550547660-d9450f859349?fit=crop&w=800&q=80&fm=jpg`
- Supreme Pepperoni Pizza: `https://images.unsplash.com/photo-1513104890138-7c749659a591?fit=crop&w=800&q=80&fm=jpg`
- Deluxe Salmon & Tuna Sushi Set: `https://images.unsplash.com/photo-1579684947550-22e945225d9a?fit=crop&w=800&q=80&fm=jpg`
- Brown Sugar Boba Milk Tea: `https://images.unsplash.com/photo-1558857563-b371033873b8?fit=crop&w=800&q=80&fm=jpg`

Three background photographs are also stored locally under `assets/images/backgrounds/` and use the same Unsplash License. Exact local filenames and download URLs:

- `shared-food-table.jpg`: `https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1950&q=80&fm=jpg`
- `discovery-pasta.jpg`: `https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=1800&q=85&fm=jpg`
- `produce-promo.jpg`: `https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=1000&q=80&fm=jpg`

The local `assets/images/food-placeholder.svg` is an original Savora fallback and is not a third-party asset.
