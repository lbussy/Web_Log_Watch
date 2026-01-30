<?php
/**
 * view_logs.php
 * ------------
 * Log viewer UI for Server-Sent Events from log_stream.php.
 *
 * Expects log_stream.php to emit SSE `data:` lines containing JSON objects in the
 * unified schema described in the user's pseudo-OpenAPI summary:
 * - type: "journal" | "internal"
 * - playback: bool
 * - __CURSOR: string|null
 * - __REALTIME_TIMESTAMP: int (microseconds since epoch)
 * - PRIORITY: "0".."7" (string) or null
 * - SYSLOG_IDENTIFIER, MESSAGE, _SYSTEMD_UNIT, HOSTNAME, PID, UID, GID
 *
 * This page maps journald PRIORITY to panes:
 * Emerg (0), Alert (1), Crit (2), Error (3), Warn (4), Notice (5), Info (6), Debug (7).
 * Internal adapter events are shown in the "internal" pane.
 */
declare(strict_types=1);

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https:' : 'http:';
$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logs</title>

    <!-- Bootstrap + jQuery (CDN). -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        .logs-card .card-body {
            position: relative;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.9rem;
            background: #0b0f14;
            color: #d9e1ea;
            border-radius: 0 0 .5rem .5rem;
        }

        /*
         * Keep the scrollable area separate from overlays (like the jump button).
         * If an overlay lives inside the element that scrolls, it will scroll out
         * of view with the content.
         */
        #log-scroll {
            height: 65vh;
            overflow-y: auto;
        }
        .logs-line {
            white-space: pre-wrap;
            word-break: break-word;
        }
        .logs-muted {
            opacity: 0.75;
        }
        .logs-playback {
            opacity: 0.70;
        }
        .logs-header {
            font-size: 0.95rem;
        }
        .logs-toolbar .form-select,
        .logs-toolbar .form-control,
        .logs-toolbar .btn {
            font-size: 0.9rem;
        }
    </style>
</head>

<body class="bg-body-tertiary">
<div class="container py-3">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <div class="h4 mb-0">Log Stream</div>
            <div class="text-body-secondary logs-header">systemd-journald via SSE</div>
        </div>
        <div class="d-flex gap-2">
            <button id="btn-clear" class="btn btn-outline-secondary btn-sm" type="button">Clear</button>
            <button id="btn-reconnect" class="btn btn-outline-primary btn-sm" type="button">Reconnect</button>
        </div>
    </div>

    <div class="card logs-card shadow-sm">
        <div class="card-header">
            <div class="row g-2 align-items-center logs-toolbar">
                <div class="col-12 col-lg-3">
                    <label class="form-label mb-1" for="unitFilter">Unit filter</label>
                    <input id="unitFilter" class="form-control form-control-sm" type="text"
                           value="wsprrypi.service"
                           placeholder="wsprrypi.service,ssh.service or *">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1" for="prioMin">Priority Max (Emerg = 0)</label>
                    <select id="prioMin" class="form-select form-select-sm">
                        <option value="0" selected>Emerg (0)</option>
                        <option value="1">Alert (1)</option>
                        <option value="2">Crit (2)</option>
                        <option value="3">Error (3)</option>
                        <option value="4">Warn (4)</option>
                        <option value="5">Notice (5)</option>
                        <option value="6">Info (6)</option>
                        <option value="7">Debug (7)</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1" for="prioMax">Priority Min (Debug = 7)</label>
                    <select id="prioMax" class="form-select form-select-sm">
                        <option value="0">Emerg (0)</option>
                        <option value="1">Alert (1)</option>
                        <option value="2">Crit (2)</option>
                        <option value="3">Error (3)</option>
                        <option value="4">Warn (4)</option>
                        <option value="5">Notice (5)</option>
                        <option value="6">Info (6)</option>
                        <option value="7" selected>Debug (7)</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1" for="backlog">Backlog</label>
                    <input id="backlog" class="form-control form-control-sm" type="number" min="0" value="200">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1" for="playback">Playback</label>
                    <select id="playback" class="form-select form-select-sm">
                        <option value="1" selected>On</option>
                        <option value="0">Off</option>
                    </select>
                </div>
                <div class="col-12 col-lg-1 d-grid">
                    <label class="form-label mb-1">&nbsp;</label>
                    <button id="btn-apply" class="btn btn-sm btn-primary" type="button">Apply</button>
                </div>
            </div>

            <ul class="nav nav-tabs mt-3" id="logsTabs" role="tablist">
                <?php
                $tabs = [
                    ['id' => 'all',     'label' => 'All'],
                    ['id' => 'emerg',   'label' => 'Emerg'],
                    ['id' => 'alert',   'label' => 'Alert'],
                    ['id' => 'crit',    'label' => 'Crit'],
                    ['id' => 'error',   'label' => 'Error'],
                    ['id' => 'warn',    'label' => 'Warn'],
                    ['id' => 'notice',  'label' => 'Notice'],
                    ['id' => 'info',    'label' => 'Info'],
                    ['id' => 'debug',   'label' => 'Debug'],
                    ['id' => 'internal','label' => 'Internal'],
                ];
                foreach ($tabs as $idx => $t) {
                    $active = ($t['id'] === 'all') ? 'active' : '';
                    $selected = ($t['id'] === 'all') ? 'true' : 'false';
                    printf(
                        '<li class="nav-item" role="presentation">
                            <button class="nav-link %s" id="%s-tab" data-bs-toggle="tab" data-bs-target="#%s"
                                    type="button" role="tab" aria-controls="%s" aria-selected="%s">%s</button>
                        </li>',
                        $active,
                        htmlspecialchars($t['id']),
                        htmlspecialchars($t['id']),
                        htmlspecialchars($t['id']),
                        $selected,
                        htmlspecialchars($t['label'])
                    );
                }
                ?>
            </ul>
        </div>

        <div class="card-body" style="position: relative;">
            <button id="btn-jump-bottom" type="button" class="btn btn-sm btn-primary" style="display:none; position:absolute; right:12px; bottom:12px; z-index:10;">Jump to bottom</button>
            <div id="log-scroll">
                <div class="tab-content" id="logsTabContent">
                <?php
                foreach ($tabs as $t) {
                    $active = ($t['id'] === 'all') ? 'show active' : '';
                    printf(
                        '<div class="tab-pane fade %s" id="%s" role="tabpanel" aria-labelledby="%s-tab"></div>',
                        $active,
                        htmlspecialchars($t['id']),
                        htmlspecialchars($t['id'])
                    );
                }
                ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
const MAX_LINES = 8000;  // Maximum number of log lines retained per tab/container


let hiddenBuffer = [];          // Buffered log entries while the tab is not visible.
let hiddenBufferedCount = 0;    // Number of buffered entries (for UI hint).


let autoFollow = true;          // True when the user is at (or near) the bottom.
let softScrollEnabled = true;   // Soft-scroll animation when following the tail.
let inPlayback = false;         // Deterministic catch-up mode signaled by SSE events.

// Hysteresis prevents the follow-state from flapping when we're near the bottom,
// and scroll UI updates are debounced to avoid flicker during wheel/trackpad
// scrolls and programmatic smooth-follow animation.
const FOLLOW_RESUME_PX = 24;    // Resume following when within this many pixels of bottom.
const FOLLOW_BREAK_PX = 120;    // Stop following only after the user scrolls up past this.
const JUMP_HIDE_DELAY_MS = 180; // Delay hiding the jump button to avoid flicker.

let programmaticScroll = false; // True while our own code is moving scrollTop.
let scrollUiTimer = null;       // Debounce timer for scroll UI updates.
let jumpHideTimer = null;       // Delayed hide timer for the jump button.

let scrollAnimHandle = null;
let scrollTarget = 0;

function cancelScrollAnimation() {
    if (scrollAnimHandle !== null) {
        cancelAnimationFrame(scrollAnimHandle);
        scrollAnimHandle = null;
    }
    programmaticScroll = false;
}

function isNearBottom(el, thresholdPx = 24) {
    if (!el) return true;
    const remaining = el.scrollHeight - el.clientHeight - el.scrollTop;
    return remaining <= thresholdPx;
}

function remainingToBottomPx(el) {
    if (!el) return 0;
    return Math.max(0, el.scrollHeight - el.clientHeight - el.scrollTop);
}

// Update autoFollow using hysteresis so it does not flap near the threshold.
function updateAutoFollow(el) {
    const remaining = remainingToBottomPx(el);

    if (autoFollow) {
        if (remaining > FOLLOW_BREAK_PX) {
            autoFollow = false;
        }
    } else {
        if (remaining <= FOLLOW_RESUME_PX) {
            autoFollow = true;
        }
    }

    return remaining;
}

// Debounce UI changes tied to scroll events so the jump button does not flicker.
function scheduleScrollUiUpdate(fn) {
    if (scrollUiTimer) {
        clearTimeout(scrollUiTimer);
        scrollUiTimer = null;
    }
    scrollUiTimer = setTimeout(() => {
        scrollUiTimer = null;
        fn();
    }, 120);
}


function animateScrollTo(el, targetTop) {
    if (!el) return;

    scrollTarget = Math.max(0, targetTop);

    // Mark as programmatic so the scroll listener does not flap UI state.
    programmaticScroll = true;

    if (scrollAnimHandle !== null) {
        return; // Animation loop already running; it will converge on the new target.
    }

    const step = () => {
        const current = el.scrollTop;
        const delta = scrollTarget - current;

        // Close enough.
        if (Math.abs(delta) < 1) {
            el.scrollTop = scrollTarget;
            scrollAnimHandle = null;
            programmaticScroll = false;

            // If we ended at the bottom, hide the jump button (with delay).
            updateAutoFollow(el);
            if (autoFollow) {
                setJumpButton(0);
            }
            return;
        }

        // Ease toward the target.
        el.scrollTop = current + (delta * 0.25);
        scrollAnimHandle = window.requestAnimationFrame(step);
    };

    scrollAnimHandle = window.requestAnimationFrame(step);
}



function trimContainer(container) {
    if (!container) return;
    const over = container.childElementCount - MAX_LINES;
    if (over <= 0) return;

    // Remove the oldest nodes first.
    for (let i = 0; i < over; i += 1) {
        if (!container.firstElementChild) break;
        container.removeChild(container.firstElementChild);
    }
}

function setJumpButton(count) {
    const btn = document.getElementById("btn-jump-bottom");
    if (!btn) return;

    const show = (label) => {
        if (jumpHideTimer) {
            clearTimeout(jumpHideTimer);
            jumpHideTimer = null;
        }
        btn.style.display = "inline-block";
        btn.textContent = label;
    };

    const hideDelayed = () => {
        if (jumpHideTimer) {
            clearTimeout(jumpHideTimer);
        }
        jumpHideTimer = setTimeout(() => {
            btn.style.display = "none";
            btn.textContent = "Jump to bottom";
            jumpHideTimer = null;
        }, JUMP_HIDE_DELAY_MS);
    };

    // If count is null/undefined, show the button without a count.
    if (count === null || typeof count === "undefined") {
        show("Jump to bottom");
        return;
    }

    if (count > 0) {
        show(`Jump to bottom (${count})`);
        return;
    }

    // count <= 0: hide, but with a small delay to avoid flicker while scrolling.
    hideDelayed();
}


function appendLineToPane(paneId, lineText, isPlayback) {
    const pane = document.getElementById(paneId);
    if (!pane) return;

    const div = document.createElement("div");
    div.className = "logs-line" + (isPlayback ? " logs-playback" : "");
    div.textContent = lineText;

    pane.appendChild(div);
    trimContainer(pane);
}

function flushHiddenBuffer() {
    if (hiddenBuffer.length === 0) {
        setJumpButton(0);
        return;
    }

    const flushedCount = hiddenBufferedCount;

    for (const item of hiddenBuffer) {
        appendLineToPane(item.paneId, item.text, item.playback);

        if (item.alsoAll && item.paneId !== "all") {
            appendLineToPane("all", item.text, item.playback);
        }
    }

    hiddenBuffer = [];
    hiddenBufferedCount = 0;

    if (autoFollow || inPlayback) {
        scrollLogsToBottom(true);
        setJumpButton(0);
    } else {
        setJumpButton(flushedCount);
    }
}



/* Globals for building the SSE URL. */
const PROTO = <?php echo json_encode($proto, JSON_UNESCAPED_SLASHES); ?>;
const HOSTNAME = <?php echo json_encode($host, JSON_UNESCAPED_SLASHES); ?>;
const CURRENT_PATH = <?php echo json_encode($path, JSON_UNESCAPED_SLASHES); ?>;

function debugConsole(level, ...args) {
    // Keep this simple; integrate with your logger if needed.
    if (level === "debug") {
        // Comment this out if you want to reduce console noise.
        console.debug(...args);
        return;
    }
    if (level === "info")  { console.info(...args);  return; }
    if (level === "warn")  { console.warn(...args);  return; }
    if (level === "error") { console.error(...args); return; }
    console.log(...args);
}

function bindLogViewActions() {
    $(document).on("shown.bs.tab", 'button[data-bs-toggle="tab"]', () => {
        // When switching tabs, force the view to the bottom.
        scrollLogsToBottom(true);
    });

    $("#btn-clear").on("click", () => {
        $("#logsTabContent .tab-pane").empty();
    });

    $("#btn-reconnect").on("click", () => {
        restartLogStream();
    });

    $("#btn-jump-bottom").on("click", () => {
        autoFollow = true;
        scrollLogsToBottom(true);
        setJumpButton(0);
    });

    $("#btn-apply").on("click", () => {
        restartLogStream();
    });

    // Auto-follow is enabled only when the user is at (or near) the bottom.
    // If they scroll up to read history, we stop auto-following until they return.
    const scrollContainer = document.getElementById("log-scroll");
    if (scrollContainer) {
        autoFollow = isNearBottom(scrollContainer);

        // If the user starts interacting with the scroll area, immediately cancel any
        // in-progress programmatic scroll so the view is not pulled back down.
        const cancelOnUserIntent = () => {
            cancelScrollAnimation();
        };
        scrollContainer.addEventListener("wheel", cancelOnUserIntent, { passive: true });
        scrollContainer.addEventListener("touchstart", cancelOnUserIntent, { passive: true });
        scrollContainer.addEventListener("mousedown", cancelOnUserIntent, { passive: true });

        scrollContainer.addEventListener("scroll", () => {
            if (programmaticScroll) {
                return;
            }

            scheduleScrollUiUpdate(() => {
                updateAutoFollow(scrollContainer);

                // If the user scrolls up, offer a quick way to jump back to the tail.
                // Show the buffered count if any accumulated while hidden.
                if (autoFollow) {
                    setJumpButton(0);
                } else {
                    setJumpButton(hiddenBufferedCount > 0 ? hiddenBufferedCount : null);
                }
            });
        }, { passive: true });
    }

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) {
            flushHiddenBuffer();
        }
    });

    window.addEventListener("focus", () => {
        flushHiddenBuffer();
    });
}


function scrollLogsToBottom(force = false) {
    const scrollContainer = document.getElementById("log-scroll");
    if (!scrollContainer) return;

    if (!force && !autoFollow) {
        return;
    }

    const target = Math.max(0, scrollContainer.scrollHeight - scrollContainer.clientHeight);

    // During playback we hard-snap on every append so the initial backlog can
    // never "outrun" the scroll position.
    if (inPlayback) {
        cancelScrollAnimation();

        programmaticScroll = true;
        scrollContainer.scrollTop = target;
        programmaticScroll = false;

        // Ensure UI reflects "at bottom" after the snap.
        updateAutoFollow(scrollContainer);
        setJumpButton(0);
        return;
    }

    if (softScrollEnabled && ("scrollBehavior" in document.documentElement.style)) {
        // Use our own easing loop to avoid repeated native smooth-scroll resets.
        animateScrollTo(scrollContainer, target);
    } else {
        programmaticScroll = true;
        scrollContainer.scrollTop = target;
        programmaticScroll = false;

        updateAutoFollow(scrollContainer);
        if (autoFollow) {
            setJumpButton(0);
        }
    }
}


function priorityToPane(priorityStr) {
    // Syslog priority: Emerg (0), Alert (1), Crit (2), 3 err, Warn (4), Notice (5), Info (6), Debug (7).
    switch (String(priorityStr)) {
        case "0": return "emerg";
        case "1": return "alert";
        case "2": return "crit";
        case "3": return "error";
        case "4": return "warn";
        case "5": return "notice";
        case "7": return "debug";
        case "6":
        default:  return "info";
    }
}

function formatPrefix(payload) {
    // __REALTIME_TIMESTAMP is microseconds. Convert to ms for JS Date.
    let ts = "";
    if (payload.__REALTIME_TIMESTAMP) {
        const ms = Math.floor(Number(payload.__REALTIME_TIMESTAMP) / 1000);
        if (!Number.isNaN(ms)) {
            ts = `[${new Date(ms).toLocaleString()}] `;
        }
    }

    const ident = payload.SYSLOG_IDENTIFIER ? String(payload.SYSLOG_IDENTIFIER) : "";
    const unit  = payload._SYSTEMD_UNIT ? String(payload._SYSTEMD_UNIT) : "";
    const pid   = (payload.PID !== null && payload.PID !== undefined) ? String(payload.PID) : "";

    let meta = "";
    if (ident && unit && pid) meta = `${ident} (${unit})[${pid}] `;
    else if (ident && unit)   meta = `${ident} (${unit}) `;
    else if (ident && pid)    meta = `${ident}[${pid}] `;
    else if (ident)           meta = `${ident} `;
    else if (unit)            meta = `${unit} `;
    else if (pid)             meta = `[${pid}] `;

    const playback = payload.playback ? "[PLAYBACK] " : "";
    return ts + playback + meta;
}

let evt = null;
let lastEventAtMs = 0;
let watchdogTimer = null;
let reconnectAttempts = 0;
let reconnectPending = false;

let isReloading = false;

function buildStreamUrl() {
    const url = new URL(`${PROTO}//${HOSTNAME}${CURRENT_PATH}/log_stream.php`);

    // Apply filters from controls.
    const playback = $("#playback").val();
    const backlog  = $("#backlog").val();
    const prioMin  = $("#prioMin").val();
    const prioMax  = $("#prioMax").val();
    const unit     = $("#unitFilter").val();

    if (playback !== "" && playback !== null) url.searchParams.set("playback", playback);
    if (backlog  !== "" && backlog  !== null) url.searchParams.set("backlog", backlog);
    if (prioMin  !== "" && prioMin  !== null) url.searchParams.set("priority_min", prioMin);
    if (prioMax  !== "" && prioMax  !== null) url.searchParams.set("priority_max", prioMax);
    if (unit     !== "" && unit     !== null) url.searchParams.set("unit", unit);

    return url.toString();
}

function startLogStream() {
    const url = buildStreamUrl();
    debugConsole("info", "Connecting to", url);

    evt = new EventSource(url);

    lastEventAtMs = Date.now();
    reconnectAttempts = 0;
    reconnectPending = false;
    isReloading = false;

    window.addEventListener("beforeunload", () => {
        isReloading = true;
        if (evt) evt.close();
    });

    evt.onopen = () => {
        debugConsole("debug", "Connected to log stream");
        // Do not assume playback is running. We enter/exit playback deterministically
        // via explicit SSE boundary events from log_stream.php.
        inPlayback = false;
    };

    const handler = (e) => {
        lastEventAtMs = Date.now();
        reconnectAttempts = 0;
        reconnectPending = false;

        try {
            const raw = (e.data ?? "").toString().trim();

            // Some servers send plain-text heartbeat/status lines or accidentally include
            // a leading "data:" prefix inside the payload. Normalize before parsing.
            let normalized = raw;
            if (normalized.toLowerCase().startsWith("data:")) {
                normalized = normalized.slice(5).trim();
            }

            if (normalized === "") {
                return;
            }

            let payload;

            // If it's not JSON, treat it as a plain message.
            const first = normalized[0];
            if (first !== "{" && first !== "[") {
                payload = {
                    type: (e.type && e.type !== "message") ? e.type : "internal",
                    playback: false,
                    __REALTIME_TIMESTAMP: null,
                    PRIORITY: null,
                    SYSLOG_IDENTIFIER: "log_stream.php",
                    MESSAGE: normalized,
                    _SYSTEMD_UNIT: null,
                    HOSTNAME: null,
                    PID: null,
                    UID: null,
                    GID: null
                };
            } else {
                payload = JSON.parse(normalized);
            }

            // Some implementations may send named SSE events (event: journal/internal).
            // Prefer payload.type; otherwise infer from the event name.
            const inferredType =
                (payload.type ?? ((e.type && e.type !== "message") ? e.type : null));

            // Determine target pane.
            let paneId = "info";
            if (inferredType === "internal") {
                paneId = "internal";
            } else if (payload.PRIORITY !== undefined && payload.PRIORITY !== null) {
                paneId = priorityToPane(payload.PRIORITY);
            }

            const isInternal = (inferredType === "internal");
            const alsoAll = !isInternal;

            // Extract message; never allow undefined.
            const msg = (payload.MESSAGE ?? "").toString();

            // Prefix with timestamp + metadata.
            const prefix = formatPrefix(payload);

            const lineText = prefix + msg;

            // When the page is not visible, browsers may throttle animation frames
            // and delay layout/paint. Buffer incoming lines and flush on focus.
            if (document.hidden) {
                hiddenBuffer.push({
                    paneId: paneId,
                    alsoAll: alsoAll,
                    text: lineText,
                    playback: !!payload.playback
                });
                hiddenBufferedCount += 1;
                return;
            }

            const $pane = $("#" + paneId);
            const $allPane = $("#all");

            const $line = $("<div>").addClass("logs-line").text(lineText);
            if (payload.playback) $line.addClass("logs-playback");

            if ($pane.length === 0) {
                debugConsole("warn", `Unknown pane '${paneId}', using 'info'`);
                const $info = $("#info");
                $info.append($line);
                trimContainer($info[0]);
            } else {
                $pane.append($line);
                trimContainer($pane[0]);
            }

            if (alsoAll && $allPane.length > 0 && paneId !== "all") {
                // Clone so each pane owns its DOM node.
                $allPane.append($line.clone(true));
                trimContainer($allPane[0]);
            }

            scrollLogsToBottom();
        } catch (err) {
            debugConsole("error", "Parse error", err, (e.data ?? "").toString().slice(0, 200));
        }
    };

    // Default (unnamed) messages.
    evt.onmessage = handler;

    // Named event support (event: journal/internal/etc.).
    // If the backend emits `event: journal`, onmessage will NOT fire.
    evt.addEventListener("journal", handler);
    evt.addEventListener("internal", handler);
    // Deterministic replay boundaries.
    evt.addEventListener("playback_start", () => {
        lastEventAtMs = Date.now();
        inPlayback = true;
        autoFollow = true;
        scrollLogsToBottom(true);
    });
    evt.addEventListener("playback_end", () => {
        lastEventAtMs = Date.now();
        inPlayback = false;
        autoFollow = true;
        scrollLogsToBottom(true);
    });
    evt.addEventListener("status", handler);
    evt.addEventListener("debug", handler);
    evt.addEventListener("error", handler);
    evt.addEventListener("heartbeat", handler);

    evt.onerror = () => {
        if (!evt) return;
        if (evt.readyState === EventSource.CLOSED && !isReloading) {
            debugConsole("warn", "SSE connection closed unexpectedly");
        }
        // Otherwise: browser will auto-reconnect.
    };
}

function stopLogStream() {
    if (evt) {
        evt.close();
        evt = null;
    }
}

function restartLogStream() {
    stopLogStream();
    startLogStream();
}

function scheduleWatchdogReconnect(reason) {
    if (reconnectPending) return;
    reconnectPending = true;

    const baseDelayMs = 1000;
    const maxDelayMs = 30000;
    const delayMs = Math.min(maxDelayMs, baseDelayMs * Math.pow(2, reconnectAttempts));
    reconnectAttempts += 1;

    debugConsole("warn", "Watchdog reconnect scheduled in", delayMs + "ms", "Reason:", reason);

    setTimeout(() => {
        reconnectPending = false;
        restartLogStream();
    }, delayMs);
}

function startWatchdog() {
    if (watchdogTimer) return;

    // If no events arrive for this long, force a reconnect.
    // This should never trigger during normal operation, since log_stream.php emits a heartbeat.
    const stallThresholdMs = 60000;

    watchdogTimer = window.setInterval(() => {
        if (!evt) return;
        if (isReloading) return;

        const ageMs = Date.now() - (lastEventAtMs || 0);
        if (evt.readyState === EventSource.OPEN && lastEventAtMs && ageMs > stallThresholdMs) {
            scheduleWatchdogReconnect("No SSE events for " + Math.floor(ageMs / 1000) + "s");
        }
    }, 5000);
}

$(document).ready(() => {
    bindLogViewActions();
    startWatchdog();
    startLogStream();
});
})();
</script>

</body>
</html>
