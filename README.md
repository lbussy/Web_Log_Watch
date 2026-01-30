# Web_Log_Watch

Web_Log_Watch is a **PHP systemd-journald → JSON → Server‑Sent Events (SSE)** adapter
with a **ready‑to‑use web log viewer UI**.

It is designed as a **drop‑in, self‑contained log streaming component** that can be
embedded into other projects or used standalone for local and admin‑only dashboards.

This repository contains:

- A **journald → SSE transport layer** (`log_stream.php`)
- A **browser‑based log viewer UI** (`view_logs.php`)
- A **strict, unified JSON event schema**
- Cursor‑safe resume using SSE `Last‑Event‑ID`
- Server‑side filtering (priority, unit)
- Playback / backlog support
- Heartbeats and internal diagnostic events

This README is the **authoritative end‑user documentation** for the project.

---

## Key Features

- Stream **systemd-journald** logs in real time
- JSON‑encoded events over **SSE (`text/event-stream`)**
- Backlog replay (`journalctl -n`) on first connect
- Cursor‑based resume without duplicates
- Unified schema for:
  - Journald entries
  - Internal adapter events
  - Heartbeats
- Server‑side filtering:
  - Priority range
  - systemd unit(s)
- Fully working **web UI** included
- No JavaScript frameworks required
- Designed for **local or trusted environments**
- UI‑agnostic transport layer usable by other projects

---

## Repository Contents

| File | Purpose |
| ----- | -------- |
| `log_stream.php` | Journald → JSON → SSE adapter |
| `view_logs.php` | Browser UI for viewing streamed logs |
| `API_syslog.md` | Low‑level streaming API specification |

---

## System Requirements

- Linux with **systemd**
- PHP **8.1+**
- `journalctl` available
- Web server:
  - Apache + mod_php **or**
  - Nginx + PHP‑FPM
- Browser with **EventSource / SSE** support

Non‑systemd systems are **not supported**.

---

## Installation

1. Copy files into your web root or project:

    ```bash
    cp log_stream.php view_logs.php /var/www/html/
    ```

2. Ensure PHP can execute `journalctl`.

3. Grant journald access to the web server user (see **Permissions**).

4. Open the UI:

    ```text
    http://localhost/view_logs.php
    ```

---

## Permissions & Security (Required)

The web server user **must be able to read the system journal**.

On most systems this requires membership in the `systemd-journal` group.

```bash
sudo usermod -aG systemd-journal www-data
sudo systemctl restart apache2   # or nginx / php-fpm
```

### Security Notes

- Each connected client spawns a `journalctl` process
- Intended for:
  - Local dashboards
  - Admin UIs
  - Trusted networks
- **Not** intended for public, unauthenticated internet exposure
- Protect with:
  - Authentication
  - Firewall rules
  - Reverse proxy ACLs

---

## Architecture Overview

```text
systemd-journald
        │
        │ journalctl
        ▼
  log_stream.php
        │
        │ SSE (JSON)
        ▼
Browser / UI / Consumer
```

The PHP layer is a **transport adapter**, not a renderer.
All events are emitted using a **single unified schema**.

---

## Unified Event Schema

Every SSE `data:` message contains **one JSON object**.

```json
{
  "type": "journal" | "internal",
  "playback": true | false,
  "__CURSOR": "s=...;i=...;b=...;m=...;t=...;x=..." | null,
  "__REALTIME_TIMESTAMP": 1738198123456789,
  "PRIORITY": "0".."7",
  "SYSLOG_IDENTIFIER": "wsprrypi",
  "MESSAGE": "Started service",
  "_SYSTEMD_UNIT": "wsprrypi.service",
  "HOSTNAME": "host",
  "PID": 1234,
  "UID": 0,
  "GID": 0
}
```

### Event Types

| Type | Meaning |
| ---- | -------- |
| `journal` | Real journald entry |
| `internal` | Adapter / heartbeat / diagnostic event |

---

## Cursor & Resume (Critical Behavior)

- Journald events include an SSE `id:` equal to the URL‑encoded `__CURSOR`
- Browsers automatically resend this as `Last‑Event‑ID` on reconnect
- Server resumes using `journalctl --after-cursor`

### Consumer Rule (Important)

Persist **only** cursors from:

```js
type === "journal" && __CURSOR !== null
```

Internal events **must not** advance the cursor.

---

## Query Parameters

### Playback & Backlog

| Parameter | Default | Description |
| --------- | -------- | ------------- |
| `playback` | `1` | Enable replay/backlog |
| `backlog` | `200` | Number of entries to replay |

Replay is **skipped** if `Last-Event-ID` is present.

---

### Priority Filtering

Mapped directly to `journalctl -p`.

| Parameter | Description |
| --------- | ------------- |
| `priority_min` | Lowest priority (0–7) |
| `priority_max` | Highest priority (0–7) |

Example:

```text
log_stream.php?priority_max=4
```

---

### systemd Unit Filtering

| Parameter | Description |
| --------- | ------------- |
| `unit` | Comma‑separated unit list |
| `unit=*` | Disable unit filtering |

Examples:

```text
log_stream.php?unit=ssh.service
log_stream.php?unit=ssh.service,cron.service
log_stream.php?unit=*
```

---

### Heartbeats

Keeps idle connections alive.

| Parameter | Default | Description |
| --------- | -------- | ------------- |
| `heartbeat` | `15` | Seconds between heartbeats |

Heartbeat events:

- `type: "internal"`
- `MESSAGE: "[HEARTBEAT]"`

---

## Included Web UI (`view_logs.php`)

The included UI provides:

- Live log streaming
- Severity‑based tabs
- “All” view (journal only)
- Priority range controls
- Automatic resume
- Visual separation of internal events
- Zero build step
- Pure PHP + JS

It consumes **the same API** exposed for external use.

---

## Typical Consumer Flow

1. Open `EventSource` to `log_stream.php`
2. Parse unified JSON events
3. Render or store as desired
4. Persist last journald cursor
5. Allow browser SSE reconnects
6. Optionally control playback and filtering

---

## Limitations

- One `journalctl` process per client
- Not intended for high fan‑out streaming
- Requires systemd
- Requires journal read permissions

---

## License

MIT License.

This project is UI‑agnostic, transport‑only by design.
You are free to embed it into other systems and build custom UIs on top.

---

## Full Streaming API Reference

See **`API_syslog.md`** for:

- Complete schema
- Resume semantics
- Internal event definitions
- OpenAPI‑style reference
- JavaScript consumer examples
