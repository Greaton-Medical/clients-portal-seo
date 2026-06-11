<?php
define('CACHE_DIR', '/tmp/portal_cache');
define('MONDAY_CACHE_TTL', 60);

function cache_get(string $key): ?array {
    $file = _cache_file($key);
    if (!file_exists($file)) {
        error_log("[CACHE] MISS: $key (not found)");
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        error_log("[CACHE] MISS: $key (read error)");
        return null;
    }
    $data = json_decode($raw, true);
    if (!isset($data['expires_at'], $data['payload'])) {
        error_log("[CACHE] MISS: $key (invalid format)");
        return null;
    }
    if (time() > $data['expires_at']) {
        error_log("[CACHE] MISS: $key (expired)");
        return null;
    }
    error_log("[CACHE] HIT: $key (expires in " . ($data['expires_at'] - time()) . "s)");
    return $data['payload'];
}

function cache_set(string $key, array $value, int $ttl_seconds): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0700, true);
    }
    $data = ['expires_at' => time() + $ttl_seconds, 'payload' => $value];
    file_put_contents(_cache_file($key), json_encode($data), LOCK_EX);
    error_log("[CACHE] SET: $key (ttl={$ttl_seconds}s)");
}

function cache_invalidate(string $key): void {
    $file = _cache_file($key);
    if (file_exists($file)) {
        unlink($file);
        error_log("[CACHE] INVALIDATED: $key");
    }
}

function _cache_file(string $key): string {
    return CACHE_DIR . '/' . preg_replace('/[^a-z0-9_]/', '_', strtolower($key)) . '.json';
}
