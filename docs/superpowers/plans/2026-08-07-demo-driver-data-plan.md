# Demo Driver Data Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the three existing Savora Driver accounts and their live locations so the demo can dispatch orders near the Customer and restaurants in Cai Rang.

**Architecture:** This is a database-only demo-data update. Keep users 3, 5, and 6, update their contact/profile fields, and upsert matching `driver_locations` rows. Preserve the existing dispatch rules: only active, eligible, online Drivers with a location recorded within five minutes are candidates.

**Tech Stack:** XAMPP MySQL on `localhost:3307`, database `savora_db`, PowerShell UTF-8 byte conversion, MySQL transactions, and read-only SQL verification.

## Global Constraints

- Do not create new Driver users.
- Do not change passwords, historical deliveries, the dispatch algorithm, or restaurant/customer data.
- Target only existing Driver user IDs `3`, `5`, and `6`.
- Store the city as the exact UTF-8 value `Thành phố Cần Thơ`.
- Keep existing vehicles, license plates, ratings, eligibility, service-area assignments, and account statuses unless a field is explicitly listed below.
- Set `driver_locations.recorded_at = NOW()` for immediate testing; do not bypass the five-minute freshness rule.
- Run the data mutation as one transaction and verify it with fresh queries before reporting completion.

---

### Task 1: Capture the current Driver snapshot

**Files:**
- No repository files modified.
- Database read-only snapshot from `savora_db`.

**Interfaces:**
- Consumes: XAMPP MySQL connection configured in `D:\Xampp\htdocs\Savora\lib\database.php:7-16`.
- Produces: current IDs, versions, and existing values for the transaction guard.

- [ ] **Step 1: Run the read-only snapshot**

```powershell
& 'D:\Xampp\mysql\bin\mysql.exe' -uroot -P3307 -D savora_db -e "SELECT u.id,u.username,u.email,u.phone,u.status,dp.version AS driver_version,dp.availability_status,dp.latitude,dp.longitude FROM users u JOIN driver_profiles dp ON dp.user_id=u.id WHERE u.id IN (3,5,6) AND u.role='driver' ORDER BY u.id;"
```

Expected: exactly three rows for users `3`, `5`, and `6`. Abort the mutation if any ID is missing or has a role other than `driver`.

---

### Task 2: Prepare the exact Driver data

**Files:**
- No repository files modified.
- Data target: `users`, `driver_profiles`, and `driver_locations` in `savora_db`.

**Interfaces:**
- Consumes: the snapshot from Task 1 and the restaurant/customer coordinates already stored in the database.
- Produces: one deterministic update set for users `3`, `5`, and `6`.

- [ ] **Step 1: Use this data set**

| User ID | Username | Email | Phone | Full address | Latitude | Longitude | Availability | Vehicle color |
| ---: | --- | --- | --- | --- | ---: | ---: | --- | --- |
| 3 | `driver` | `driver@savora.test` | `0901001001` | `20 Đường Số 26, Phường Cái Răng, Thành phố Cần Thơ, Việt Nam` | `9.9855000` | `105.7590000` | `online` | `Trắng` |
| 5 | `driver-nearby-2` | `alex@savora.test` | `0901001002` | `76 Đường Võ Nguyên Giáp, Phường Cái Răng, Thành phố Cần Thơ, Việt Nam` | `9.9908000` | `105.7658000` | `online` | `Đen` |
| 6 | `driver-nearby-3` | `jordan@savora.test` | `0901001003` | `35 Đường Mai Chí Thọ, Phường Cái Răng, Thành phố Cần Thơ, Việt Nam` | `9.9789000` | `105.7556000` | `offline` | `Xanh dương` |

Split each full address into `address_line1` as the street portion and
`address_line2` as `Phường Cái Răng`. Set `state = Cần Thơ`, `postal_code =
94908`, `country = Việt Nam`, `location_method = manual`, and preferences JSON
to `{"newOffers":true,"soundAlerts":true,"cashOnDelivery":true,"avoidHighways":false}`.

- [ ] **Step 2: Preserve the existing non-target fields**

Keep the current `full_name`, vehicle type/model, license plate, service area,
ratings, acceptance/completion rates, `eligibility_status`, account status, and
password hashes. Increment `users.version` and `driver_profiles.version` once
for each changed row.

---

### Task 3: Apply the transaction to XAMPP MySQL

**Files:**
- No repository files modified.
- Database mutation: `users`, `driver_profiles`, `driver_locations`.

**Interfaces:**
- Consumes: the exact values from Task 2.
- Produces: complete Driver profiles and three current location rows.

- [ ] **Step 1: Build SQL with UTF-8 byte literals**

Use PowerShell `[Text.Encoding]::UTF8.GetBytes()` to convert every Vietnamese
value to hex, then send SQL expressions of the form
`CONVERT(0x<hex> USING utf8mb4)` to `mysql.exe`. This is required because the
Windows MySQL CLI previously converted Vietnamese characters to byte `3F`.

- [ ] **Step 2: Execute one transaction**

Within one `START TRANSACTION`/`COMMIT` block:

1. Update `users.email`, `users.phone`, and increment `users.version` for IDs
   `3`, `5`, and `6`.
2. Update each matching `driver_profiles` row with city, address, vehicle
   color, preferences JSON, coordinates, location metadata, availability, and
   `version = version + 1`.
3. Upsert `driver_locations(driver_user_id, latitude, longitude,
   accuracy_meters, recorded_at, version)` with accuracy `10.00`, `NOW()`, and
   version `1` for each Driver. If a row already exists, update coordinates,
   accuracy, timestamp, and increment its version.

Abort and roll back if any targeted update affects a row count other than one,
or if any upsert fails.

---

### Task 4: Verify profile completeness and dispatch eligibility

**Files:**
- No repository files modified.
- Read-only verification against `savora_db`.

**Interfaces:**
- Consumes: the committed database transaction from Task 3.
- Produces: evidence that all three profiles and location rows satisfy the
  design, including the dispatch freshness rule.

- [ ] **Step 1: Verify required fields and UTF-8 storage**

```powershell
& 'D:\Xampp\mysql\bin\mysql.exe' -uroot -P3307 -D savora_db -e "SELECT u.id,u.username,HEX(u.email) AS email_hex,HEX(u.phone) AS phone_hex,HEX(dp.city) AS city_hex,HEX(dp.address) AS address_hex,HEX(dp.vehicle_color) AS color_hex,dp.latitude,dp.longitude,dp.availability_status,dp.eligibility_status,dl.latitude AS live_latitude,dl.longitude AS live_longitude,dl.recorded_at FROM users u JOIN driver_profiles dp ON dp.user_id=u.id JOIN driver_locations dl ON dl.driver_user_id=u.id WHERE u.id IN (3,5,6) ORDER BY u.id;"
```

Expected: three rows; every email, phone, address, city, color, and coordinate
is non-empty; profile/live coordinates match; no target text contains a `3F`
byte; users 3 and 5 are `online`; user 6 is `offline`; all are `eligible`.

- [ ] **Step 2: Verify freshness and candidate count**

```powershell
& 'D:\Xampp\mysql\bin\mysql.exe' -uroot -P3307 -D savora_db -e "SELECT COUNT(*) AS fresh_online_candidates FROM driver_profiles dp JOIN users u ON u.id=dp.user_id JOIN driver_locations dl ON dl.driver_user_id=dp.user_id LEFT JOIN deliveries d ON d.driver_user_id=dp.user_id AND d.status IN ('assigned','arrived','picked_up') AND d.superseded_at IS NULL WHERE u.status='active' AND dp.eligibility_status='eligible' AND dp.availability_status='online' AND dl.recorded_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND d.id IS NULL;"
```

Expected: `fresh_online_candidates = 2`.

- [ ] **Step 3: Verify distances**

Calculate each Driver's distance to customer coordinates `9.9822268,
105.7580846` and each restaurant's pickup coordinates. Expected: all three
Drivers are within roughly five kilometers of the Customer, and Drivers 3 and
5 are close to different restaurants so nearest-driver ordering is visible.

No Git commit is needed for this task because the approved change is a direct
demo-database update and no repository source file is modified.
