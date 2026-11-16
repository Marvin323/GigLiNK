# GigLink JSON API

Base URL: `http://localhost/giglink/api/` (replace host for production).

**Auth** is a bearer token obtained from `POST /login`. Send on every call:

```
Authorization: Bearer <token>
```

A token is valid 30 days and can be revoked by `POST /logout`.

## Endpoints

### POST /api/login.php
Body: `{"username": "alice", "password": "password"}`
→ `{ ok: true, token: "...", user: { id, username, role, phone, email, photo } }`

### POST /api/logout.php
Revokes the current token.

### GET /api/me.php
Current user + unread counters.

### GET /api/jobs.php?q=&category=&location=&minpay=&today=1&sort=soonest&p=1&per=20
Public open and filled listings. Supported sorts are newest (the default), `soonest`, and `pay`.
All sorts use descending job ID to break ties. Category and location take numeric IDs.
The response includes `jobs`, `total`, `page`, `per`, `filters`, and `remaining_slots` on each job.

### GET /api/job.php?id=5
Full job + employer summary.

### POST /api/apply.php
Body: `{"job_id": 5, "message": "I live nearby…"}` (youth only). A successful retry returns the existing application outcome without another notification. A job that cannot take a new application returns HTTP 409.

### GET /api/notifications.php?p=1&per=25
Latest notifications. `POST` to the same URL marks all read.

### GET /api/messages.php
List message threads (only threads that have at least one message).

### GET /api/messages.php?application_id=12
Fetch one thread; also marks it read.

### POST /api/send_message.php
Body: `{"application_id": 12, "body": "On my way"}`

## Mobile client suggestions

- **Native wrap**: Capacitor around the existing PWA — one codebase, lands in both stores.
- **React Native / Flutter**: build the UI natively, hit these endpoints. Store the token in `AsyncStorage` / `flutter_secure_storage`.
