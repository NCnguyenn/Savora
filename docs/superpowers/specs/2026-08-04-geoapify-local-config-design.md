# Geoapify Local Configuration Design

## Goal

Allow the Savora Customer GPS preview to read a developer-supplied Geoapify key from a local PHP config file when Apache/PHP does not expose `SetEnv` variables to `getenv()`.

## Decision

`lib/location_service.php` will expose `savora_geoapify_api_key(?string $localPath = null): string` and resolve the key in this order:

1. `GEOAPIFY_API_KEY` from the process environment, when present.
2. The supplied `$localPath`, or `config/local.php` by default, when present and returning an array with `GEOAPIFY_API_KEY`.
3. No key, which preserves the existing safe unavailable response.

`config/local.php` will be ignored by Git. A tracked `config/local.php.example` will contain only a placeholder and instructions. The secret will never be logged, returned by an endpoint, or committed.

## Runtime Flow

The public preview endpoint calls `savora_reverse_geocode()`. The service resolves the key through a small loader before constructing the Geoapify URL. The browser contract, rate limiting, coordinate validation, normalized address response, and generic failure message remain unchanged.

## Acceptance Criteria

- A local config file with a non-empty key is used when the process environment is empty.
- The existing environment variable still takes precedence.
- Missing or malformed local config behaves as an unavailable provider, without exposing secrets.
- The local config file is ignored by Git and the example file contains no real key.
- Existing location service tests and the live XAMPP preview endpoint pass after the user fills the key.
