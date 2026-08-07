# Demo Driver Data Design

## Context

Savora already has three active demo Driver accounts: `driver` (user 3),
`driver-nearby-2` (user 5), and `driver-nearby-3` (user 6). Their vehicle and
performance fields exist, but contact details, readable addresses, profile
coordinates, and `driver_locations` rows are incomplete. Restaurant and
Customer demo data now use the Cai Rang, Can Tho area.

## Goal

Complete the existing three Driver accounts so the profile screens contain
usable demo data and dispatch can rank nearby eligible Drivers. Do not create
new Driver accounts, change passwords, delete historical records, or alter the
dispatch algorithm.

## Selected Design

Keep the existing identities, vehicles, license plates, ratings, and account
statuses. Add the missing contact, address, vehicle-color, preference, and
location data as follows:

| User | Email | Phone | Address | Coordinates | Availability |
| --- | --- | --- | --- | --- | --- |
| `driver` | `driver@savora.test` | `0901001001` | `20 Duong So 26, Phuong Cai Rang, Thanh pho Can Tho, Viet Nam` | `9.9855000, 105.7590000` | online |
| `driver-nearby-2` | `alex@savora.test` | `0901001002` | `76 Duong Vo Nguyen Giap, Phuong Cai Rang, Thanh pho Can Tho, Viet Nam` | `9.9908000, 105.7658000` | online |
| `driver-nearby-3` | `jordan@savora.test` | `0901001003` | `35 Duong Mai Chi Tho, Phuong Cai Rang, Thanh pho Can Tho, Viet Nam` | `9.9789000, 105.7556000` | offline |

The database values will use the Vietnamese spelling with full diacritics.
ASCII text is shown in the table only to keep this design file portable across
Windows terminals.

Driver profile fields will be normalized to the exact city value already used
by the Customer and restaurants (`Thanh pho Can Tho`, stored with Vietnamese
diacritics), retain their current service-area assignments, and receive the
vehicle colors white, black, and blue (stored as Vietnamese labels).
Preferences will use the application's defaults: new offers, sound alerts, and
cash-on-delivery enabled; highway avoidance disabled.

## Data Flow and Dispatch Behavior

Each account will have matching coordinates in `driver_profiles` and an
upserted row in `driver_locations`. Dispatch only considers active, eligible,
online Drivers whose `driver_locations.recorded_at` is no older than five
minutes. The two online Drivers therefore support nearest-driver and fallback
demo scenarios. The third Driver remains offline to demonstrate availability
filtering while retaining a last-known location.

Because the five-minute freshness rule is intentional, the two online Drivers
must refresh their locations from the Driver UI shortly before a live dispatch
demo. The database update will set `recorded_at = NOW()` for immediate testing,
but it will not bypass or weaken that rule.

## Update Safety

All updates will run in one MySQL transaction against XAMPP MySQL on port 3307.
Rows will be targeted by the known user IDs 3, 5, and 6. Unicode text will be
sent as UTF-8 byte literals to avoid Windows command-line encoding loss.
Optimistic-lock versions in `users` and `driver_profiles` will be incremented.
Existing passwords and historical delivery data will not be changed.

## Verification

After the transaction:

1. Confirm all three users have non-empty unique emails and phones.
2. Confirm all three profiles have address, city, vehicle color, coordinates,
   preferences, and expected availability values.
3. Confirm three `driver_locations` rows exist with matching coordinates and no
   malformed UTF-8/question-mark replacement bytes.
4. Calculate Driver-to-restaurant and Driver-to-Customer distances.
5. Confirm candidate selection can see two fresh online Drivers and excludes
   the offline Driver.
