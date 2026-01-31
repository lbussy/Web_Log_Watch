<?php
// log_stream.php
//
// Journald JSON SSE adapter with unified schema, replay + follow, cursor resume,
// consumer-controlled playback/backlog, server-side filtering on PRIORITY/UNIT,
// heartbeat events, and playback boolean.
//
// Output rule:
// - Every SSE event emitted by this script MUST contain JSON only in `data:`.
// - Optional `id:` is emitted ONLY for type="journal" events with a non-empty
//   __CURSOR value.
// - Optional `event:` is emitted as "journal" or "internal".
//
// Consumer rule:
// - Persist only the last cursor from events where type === "journal" and
//   __CURSOR is a non-empty string. Internal events do not carry cursors.
//
// Unified payload schema for ALL emitted events:
//
// {
//   "type": "journal" | "internal",
//   "playback": boolean,
//   "__CURSOR": string|null,
//   "__REALTIME_TIMESTAMP": int,   // microseconds since epoch
//   "PRIORITY": string|null,       // journald 0..7 as strings
//   "SYSLOG_IDENTIFIER": string|null,
//   "MESSAGE": string,
//   "_SYSTEMD_UNIT": string|null,
//   "HOSTNAME": string|null,
//   "PID": int|null,
//   "UID": int|null,
//   "GID": int|null
// }
//
// Consumer controls:
// - playback=0|1 (default 1) controls whether replay/backlog happens at all
// - backlog=N (default 200, clamped) controls journalctl -n N for replay
// - priority_min=0..7 / priority_max=0..7
// - unit=a.service,b.service  (default wsprrypi.service) or unit=* to disable
// - heartbeat=seconds (default 15, clamped)

declare(strict_types=1);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

set_time_limit(0);
ignore_user_abort(true);

@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');

error_reporting(E_ALL);

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

while (ob_get_level() > 0) {
    @ob_end_flush();
}
@ob_implicit_flush(true);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function now_micro(): int
{
    return (int)(microtime(true) * 1_000_000);
}

function flush_sse(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * Emit an SSE event with JSON-only data payload.
 *
 * @param array       $payload   Unified schema payload (will be JSON encoded).
 * @param string|null $eventName Optional SSE event name (journal/internal).
 * @param string|null $sseCursor Optional cursor for SSE id line (journal only).
 *
 * @return void
 */
function emit_payload(array $payload, ?string $eventName = null, ?string $sseCursor = null): void
{
    if ($sseCursor !== null && $sseCursor !== '') {
        echo 'id: ' . rawurlencode($sseCursor) . "\n";
    }

    if ($eventName !== null && $eventName !== '') {
        echo 'event: ' . $eventName . "\n";
    }

    echo 'data: ' . json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . "\n\n";

    flush_sse();
}

function base_internal_payload(bool $isPlayback): array
{
    return [
        'type' => 'internal',
        'playback' => $isPlayback,
        '__CURSOR' => null,
        '__REALTIME_TIMESTAMP' => now_micro(),
        'PRIORITY' => '6',
        'SYSLOG_IDENTIFIER' => 'log_stream.php',
        'MESSAGE' => '',
        '_SYSTEMD_UNIT' => null,
        'HOSTNAME' => gethostname() ?: null,
        'PID' => getmypid(),
        'UID' => function_exists('posix_getuid') ? posix_getuid() : null,
        'GID' => function_exists('posix_getgid') ? posix_getgid() : null,
    ];
}

function emit_internal(string $message, string $priority, ?string $unit, bool $isPlayback): void
{
    $payload = base_internal_payload($isPlayback);
    $payload['MESSAGE'] = $message;
    $payload['PRIORITY'] = $priority;
    $payload['_SYSTEMD_UNIT'] = $unit;

    emit_payload($payload, 'internal', null);
}

/**
 * Emit a deterministic playback boundary event.
 *
 * The UI uses this to switch from "catch-up" (replay/backlog) to live follow
 * without relying on timing heuristics.
 *
 * @param string $eventName "playback_start" or "playback_end".
 * @param bool   $isPlayback True when replay/backlog is active.
 *
 * @return void
 */
function emit_playback_event(string $eventName, bool $isPlayback): void
{
    $payload = base_internal_payload($isPlayback);
    $payload['MESSAGE'] = $eventName;
    $payload['PRIORITY'] = '7';

    // Use a dedicated SSE event so the client can listen without parsing text.
    emit_payload($payload, $eventName, null);
}

// Global holders for error handlers.
$__internalUnitForErrors = null;

// Convert PHP errors into internal events. (Avoid echoing raw PHP warnings.)
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use (&$__internalUnitForErrors): bool {
    $msg = '[php error] ' . $errstr . ' at ' . $errfile . ':' . (string)$errline;
    emit_internal($msg, '3', $__internalUnitForErrors, false);
    // Returning true prevents default handler output.
    return true;
});

// Capture fatal errors on shutdown.
register_shutdown_function(function () use (&$__internalUnitForErrors): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }

    $msg = '[php fatal] ' . ($err['message'] ?? 'unknown') .
        ' at ' . ($err['file'] ?? '?') . ':' . (string)($err['line'] ?? 0);

    emit_internal($msg, '3', $__internalUnitForErrors, false);
});

// -----------------------------------------------------------------------------
// Defaults
// -----------------------------------------------------------------------------
$defaultUnitName  = 'wsprrypi.service';
$defaultBacklog   = 200;
$maxBacklog       = 2000;

$defaultHeartbeat = 15;
$minHeartbeat     = 5;
$maxHeartbeat     = 60;

// -----------------------------------------------------------------------------
// Consumer controls: playback/backlog/heartbeat
// -----------------------------------------------------------------------------
$playbackParam   = $_GET['playback'] ?? '1';
$playbackEnabled = !in_array($playbackParam, ['0', 0, false, 'false', 'off'], true);

$initialBacklog = $defaultBacklog;
if (isset($_GET['backlog'])) {
    $n = filter_var($_GET['backlog'], FILTER_VALIDATE_INT);
    if ($n !== false) {
        $n = max(0, min((int)$n, $maxBacklog));
        $initialBacklog = $n;
    }
}

$heartbeatSec = $defaultHeartbeat;
if (isset($_GET['heartbeat'])) {
    $h = filter_var($_GET['heartbeat'], FILTER_VALIDATE_INT);
    if ($h !== false) {
        $h = max($minHeartbeat, min((int)$h, $maxHeartbeat));
        $heartbeatSec = $h;
    }
}

// -----------------------------------------------------------------------------
// Consumer filters: PRIORITY and _SYSTEMD_UNIT only
// -----------------------------------------------------------------------------
function clamp_priority($v): ?int
{
    if ($v === null) {
        return null;
    }

    $i = filter_var($v, FILTER_VALIDATE_INT);
    if ($i === false) {
        return null;
    }

    $i = (int)$i;
    if ($i < 0) {
        $i = 0;
    }
    if ($i > 7) {
        $i = 7;
    }

    return $i;
}

$priorityMin = clamp_priority($_GET['priority_min'] ?? null);
$priorityMax = clamp_priority($_GET['priority_max'] ?? null);

if ($priorityMin !== null && $priorityMax !== null && $priorityMin > $priorityMax) {
    $tmp = $priorityMin;
    $priorityMin = $priorityMax;
    $priorityMax = $tmp;
}

// Unit filter: default to the project unit, unless unit=* is requested.
$unitParam = $_GET['unit'] ?? null;
$units = [];
$unitFilterDisabled = false;

if (is_string($unitParam) && $unitParam !== '') {
    if ($unitParam === '*') {
        $unitFilterDisabled = true;
    } else {
        $parts = array_filter(array_map('trim', explode(',', $unitParam)));
        foreach ($parts as $u) {
            if ($u !== '') {
                $units[] = $u;
            }
        }
    }
}

if (!$unitFilterDisabled && count($units) === 0) {
    $units = [$defaultUnitName];
}

// For internal events, attach a "best" unit name (first unit or null).
$internalUnit = (!$unitFilterDisabled && count($units) > 0) ? $units[0] : null;
$__internalUnitForErrors = $internalUnit;

// Last-Event-ID → cursor resume
$lastEventIdRaw = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
$lastCursor = is_string($lastEventIdRaw) && $lastEventIdRaw !== ''
    ? rawurldecode($lastEventIdRaw)
    : null;

function build_cmd(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function proc_start(string $cmd): array
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'stderr' => 'proc_open failed'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    usleep(150000);
    $status = proc_get_status($proc);
    if (!$status['running']) {
        $err = trim(stream_get_contents($pipes[2]));
        if ($err === '') {
            $err = 'process exited immediately';
        }
        return ['ok' => false, 'stderr' => $err];
    }

    return ['ok' => true, 'proc' => $proc, 'pipes' => $pipes];
}

function send_entry(array $entry, bool $isPlayback): ?string
{
    $cursor = $entry['__CURSOR'] ?? null;

    $payload = [
        'type' => 'journal',
        'playback' => $isPlayback,
        '__CURSOR' => is_string($cursor) ? $cursor : null,
        '__REALTIME_TIMESTAMP' =>
            isset($entry['__REALTIME_TIMESTAMP'])
                ? (int)$entry['__REALTIME_TIMESTAMP']
                : now_micro(),
        'PRIORITY' => $entry['PRIORITY'] ?? null,
        'SYSLOG_IDENTIFIER' => $entry['SYSLOG_IDENTIFIER'] ?? null,
        'MESSAGE' => (string)($entry['MESSAGE'] ?? ''),
        '_SYSTEMD_UNIT' => $entry['_SYSTEMD_UNIT'] ?? null,
        'HOSTNAME' => $entry['_HOSTNAME'] ?? null,
        'PID' => isset($entry['_PID']) ? (int)$entry['_PID'] : null,
        'UID' => isset($entry['_UID']) ? (int)$entry['_UID'] : null,
        'GID' => isset($entry['_GID']) ? (int)$entry['_GID'] : null,
    ];

    $sseCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
    emit_payload($payload, 'journal', $sseCursor);

    return $sseCursor;
}

function drain_process(
    array $started,
    bool $followMode,
    ?string $internalUnit,
    int $heartbeatSec,
    bool $isPlayback
): array {
    $proc = $started['proc'];
    $pipes = $started['pipes'];

    $stdoutBuf = '';
    $stderrBuf = '';
    $lastCursor = null;
    $lastHeartbeatAt = 0;

    // Track whether each pipe is still open. When a pipe reaches EOF, it remains
    // "readable" forever, which can cause stream_select() to wake continuously.
    $outOpen = is_resource($pipes[1]);
    $errOpen = is_resource($pipes[2]);

    while (true) {
        $read = [];
        if ($outOpen && is_resource($pipes[1])) {
            $read[] = $pipes[1];
        }
        if ($errOpen && is_resource($pipes[2])) {
            $read[] = $pipes[2];
        }

        // If the process is not running and both pipes are closed/EOF, we're done.
        $status = proc_get_status($proc);
        if (!$status['running'] && !$outOpen && !$errOpen) {
            break;
        }

        // If there is nothing to read, sleep/heartbeat or wait for exit.
        if (count($read) === 0) {
            if ($followMode) {
                $now = time();
                if (($now - $lastHeartbeatAt) >= $heartbeatSec) {
                    emit_internal('[HEARTBEAT]', '7', $internalUnit, false);
                    $lastHeartbeatAt = $now;
                }
                sleep(1);
                continue;
            }

            // Non-follow replay mode: wait briefly for process exit.
            sleep(1);
            continue;
        }

        $write = [];
        $except = [];
        $ready = @stream_select($read, $write, $except, 1);
        if ($ready === false) {
            $ready = 0;
        }

        if ($ready === 0) {
            if ($followMode) {
                $now = time();
                if (($now - $lastHeartbeatAt) >= $heartbeatSec) {
                    emit_internal('[HEARTBEAT]', '7', $internalUnit, false);
                    $lastHeartbeatAt = $now;
                }
                continue;
            }

            // Replay mode: if the process has exited, do a final drain and exit.
            $status = proc_get_status($proc);
            if (!$status['running']) {
                if ($outOpen && is_resource($pipes[1])) {
                    $finalOut = stream_get_contents($pipes[1]);
                    if ($finalOut !== false && $finalOut !== '') {
                        $stdoutBuf .= $finalOut;
                    }
                    if (feof($pipes[1])) {
                        fclose($pipes[1]);
                        $outOpen = false;
                    }
                }
                if ($errOpen && is_resource($pipes[2])) {
                    $finalErr = stream_get_contents($pipes[2]);
                    if ($finalErr !== false && $finalErr !== '') {
                        $stderrBuf .= $finalErr;
                    }
                    if (feof($pipes[2])) {
                        fclose($pipes[2]);
                        $errOpen = false;
                    }
                }
                // Let the loop condition break when both pipes are closed.
            }

            continue;
        }

        foreach ($read as $r) {
            $chunk = stream_get_contents($r);

            if ($chunk !== false && $chunk !== '') {
                if ($r === $pipes[2]) {
                    $stderrBuf .= $chunk;
                } else {
                    $stdoutBuf .= $chunk;
                }
            }

            // If EOF is reached, close this pipe so we stop selecting on it.
            if (feof($r)) {
                fclose($r);
                if ($r === $pipes[2]) {
                    $errOpen = false;
                } else {
                    $outOpen = false;
                }
            }
        }

        // Process complete stderr lines.
        while (($pos = strpos($stderrBuf, "\n")) !== false) {
            $line = trim(substr($stderrBuf, 0, $pos));
            $stderrBuf = substr($stderrBuf, $pos + 1);
            if ($line !== '') {
                emit_internal('[journalctl stderr] ' . $line, '4', $internalUnit, false);
            }
        }

        // Process complete stdout JSON lines.
        while (($pos = strpos($stdoutBuf, "\n")) !== false) {
            $line = trim(substr($stdoutBuf, 0, $pos));
            $stdoutBuf = substr($stdoutBuf, $pos + 1);
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                emit_internal('[journalctl non-json] ' . $line, '4', $internalUnit, false);
                continue;
            }

            $c = send_entry($entry, $isPlayback);
            if ($c !== null) {
                $lastCursor = $c;
            }
        }
    }

    // Flush any remaining complete lines after exit.
    while (($pos = strpos($stderrBuf, "\n")) !== false) {
        $line = trim(substr($stderrBuf, 0, $pos));
        $stderrBuf = substr($stderrBuf, $pos + 1);
        if ($line !== '') {
            emit_internal('[journalctl stderr] ' . $line, '4', $internalUnit, false);
        }
    }

    while (($pos = strpos($stdoutBuf, "\n")) !== false) {
        $line = trim(substr($stdoutBuf, 0, $pos));
        $stdoutBuf = substr($stdoutBuf, $pos + 1);
        if ($line === '') {
            continue;
        }

        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            emit_internal('[journalctl non-json] ' . $line, '4', $internalUnit, false);
            continue;
        }

        $c = send_entry($entry, $isPlayback);
        if ($c !== null) {
            $lastCursor = $c;
        }
    }

    // Close any remaining pipes that are still open.
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    proc_close($proc);

    return ['lastCursor' => $lastCursor];
}

// Discover journalctl path.
$journalctlPath = trim((string)shell_exec('command -v journalctl 2>/dev/null'));
if ($journalctlPath === '') {
    emit_internal('journalctl not found in PATH', '3', $internalUnit, false);
    exit;
}

// -----------------------------------------------------------------------------
// Build journalctl filter args (shared by replay + follow).
// -----------------------------------------------------------------------------
$journalFilters = [];

// Priority filter: journalctl -p "min..max" or -p "N"
if ($priorityMin !== null || $priorityMax !== null) {
    if ($priorityMin === null) {
        $priorityMin = 0;
    }
    if ($priorityMax === null) {
        $priorityMax = 7;
    }

    $journalFilters[] = '-p';
    $journalFilters[] = ($priorityMin === $priorityMax)
        ? (string)$priorityMin
        : ((string)$priorityMin . '..' . (string)$priorityMax);
}

// Unit filter: add one -u per unit unless disabled
if (!$unitFilterDisabled) {
    foreach ($units as $u) {
        $journalFilters[] = '-u';
        $journalFilters[] = $u;
    }
}

emit_internal(
    'SSE connected. playback=' . ($playbackEnabled ? '1' : '0') .
    ' backlog=' . (string)$initialBacklog .
    ' priority=' . (($priorityMin === null && $priorityMax === null) ? 'any' :
        ((string)($priorityMin ?? 0) . '..' . (string)($priorityMax ?? 7))) .
    ' unit=' . ($unitFilterDisabled ? '*' : implode(',', $units)) .
    ' heartbeat=' . (string)$heartbeatSec . 's',
    '6',
    $internalUnit,
    false
);

// -----------------------------------------------------------------------------
// Phase 1: Replay (optional)
// -----------------------------------------------------------------------------
$cursorForFollow = null;

// If the client provided a cursor (Last-Event-ID), we will resume the follow
// loop from that point. If playback is enabled, we will still emit a small
// backlog for context, but we will scope that replay to entries after the
// provided cursor so we do not skip forward and miss unseen messages.
if ($lastCursor !== null) {
    $cursorForFollow = $lastCursor;
}

if ($playbackEnabled && $initialBacklog > 0) {
    $replayParts = array_merge(
        [$journalctlPath, '--no-pager', '-o', 'json'],
        $journalFilters
    );

    if ($lastCursor !== null) {
        $replayParts[] = '--after-cursor';
        $replayParts[] = $lastCursor;
    }

    $replayParts[] = '-n';
    $replayParts[] = (string)$initialBacklog;

    emit_playback_event('playback_start', true);
    emit_internal('journalctl replay starting', '7', $internalUnit, true);
    emit_internal('journalctl replay cmd: ' . build_cmd($replayParts), '7', $internalUnit, true);

    $started = proc_start(build_cmd($replayParts));
    if ($started['ok']) {
        $res = drain_process($started, false, $internalUnit, $heartbeatSec, true);
        $cursorForFollow = $res['lastCursor'] ?? $cursorForFollow;
        emit_internal('journalctl replay complete', '7', $internalUnit, true);
        // Signal to the client that initial catch-up is finished.
        emit_playback_event('playback_end', false);
    } else {
        emit_internal(
            'journalctl replay failed: ' . (string)($started['stderr'] ?? ''),
            '3',
            $internalUnit,
            false
        );
        // Even on failure, end playback mode so the UI can switch to live follow.
        emit_playback_event('playback_end', false);
    }
}
// -----------------------------------------------------------------------------
// Phase 2: Follow (always)
// -----------------------------------------------------------------------------
emit_internal('journalctl follow loop entering', '7', $internalUnit, false);

while (true) {
    $followParts = array_merge(
        [$journalctlPath, '--no-pager', '-o', 'json', '-f'],
        $journalFilters
    );

    if ($cursorForFollow !== null) {
        array_splice($followParts, 4, 0, ['--after-cursor', $cursorForFollow]);
    }

    emit_internal('journalctl follow starting', '7', $internalUnit, false);
    emit_internal('journalctl follow cmd: ' . build_cmd($followParts), '7', $internalUnit, false);

    $started = proc_start(build_cmd($followParts));
    if (!$started['ok']) {
        emit_internal('journalctl follow failed: ' . (string)($started['stderr'] ?? ''), '3', $internalUnit, false);
        sleep(1);
        continue;
    }

    $res = drain_process($started, true, $internalUnit, $heartbeatSec, false);
    $cursorForFollow = $res['lastCursor'] ?? $cursorForFollow;

    emit_internal('journalctl follow restarted', '4', $internalUnit, false);

    if (!$playbackEnabled) {
        $cursorForFollow = null;
    }
}
