# Geoapify GPS address configuration

Savora uses the browser's one-shot Geolocation API for coordinates and Geoapify Reverse Geocoding on the PHP server for a readable address. Customer, Driver, and Restaurant can request the current location; Admin only sees the saved result.

## Demo setup

1. Create a Geoapify project and copy its API key.
2. Set the key in the PHP process environment as `GEOAPIFY_API_KEY`.
3. Restart Apache/PHP-FPM so the web request inherits the variable.

For XAMPP on Windows, add an Apache environment entry in `httpd.conf` or the virtual-host configuration, for example:

```apache
SetEnv GEOAPIFY_API_KEY "replace-with-your-demo-key"
```

Do not put the key in JavaScript, HTML, a committed `.env` file, or a URL visible to the browser. The server calls `https://api.geoapify.com/v1/geocode/reverse` with Vietnamese output (`lang=vi`, `format=geojson`, `limit=1`).

## Runtime behavior

- GPS requests use high accuracy, a 10-second timeout, and a 60-second browser cache.
- A successful response stores the resolved address, coordinates, method (`gps`), and timestamp in the role profile.
- Choosing manual entry stores the typed address, clears latitude/longitude, and changes the method to `manual`.
- If permission is denied, the browser has no GPS, Geoapify is unavailable, or the key is missing, the UI keeps the manual form available and does not invent a coordinate-only address.
- Geoapify attribution is shown beside GPS-assisted address controls.

## Demo limits and troubleshooting

Geoapify's free plan is suitable for a small demo, but quotas and commercial terms can change. Keep requests one-shot and avoid polling. Check Apache/PHP error logs for sanitized reverse-geocoding failures; the API key and upstream response body are never sent to the browser.

If a location stays manual:

1. Confirm the browser is on HTTPS or localhost and that location permission is allowed.
2. Confirm `GEOAPIFY_API_KEY` is visible to Apache/PHP (`getenv('GEOAPIFY_API_KEY')`).
3. Confirm PHP cURL is enabled and outbound HTTPS is allowed.
4. Use the manual address field while diagnosing; manual mode remains supported by design.
