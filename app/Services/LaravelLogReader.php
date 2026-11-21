<?php

namespace App\Services;

/**
 * Reads the end of storage/logs/laravel.log for the admin log page.
 * Only the last chunk of the file is read (fseek), so a huge log costs the same
 * as a small one. Each entry keeps the time, level and the first line of the
 * message (no stack trace), with anything that looks like a secret masked.
 */
class LaravelLogReader
{
    public const LEVELS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING'];

    private const MAX_BYTES = 262144; // 256 KB is far more than 200 lines of a stack-trace log

    private const MASK = '[disembunyikan]';

    public function __construct(private ?string $path = null)
    {
    }

    /** The log file to read: laravel.log, else the newest laravel-*.log (daily channel). */
    public function path(): ?string
    {
        if ($this->path !== null) {
            return is_file($this->path) ? $this->path : null;
        }

        $single = storage_path('logs/laravel.log');
        if (is_file($single)) {
            return $single;
        }

        $daily = glob(storage_path('logs/laravel-*.log')) ?: [];
        rsort($daily);

        return $daily[0] ?? null;
    }

    /**
     * @return array{state: 'ok'|'missing'|'error', file: ?string, size: int, entries: list<array{time: string, level: string, message: string}>}
     */
    public function tail(int $lines = 200): array
    {
        $path = $this->path();
        if ($path === null) {
            return ['state' => 'missing', 'file' => null, 'size' => 0, 'entries' => []];
        }

        try {
            $size   = (int) filesize($path);
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open log file.');
            }
            $start = max(0, $size - self::MAX_BYTES);
            fseek($handle, $start);
            $chunk = (string) stream_get_contents($handle);
            fclose($handle);
        } catch (\Throwable $e) {
            return ['state' => 'error', 'file' => basename($path), 'size' => 0, 'entries' => []];
        }

        $rows = preg_split('/\r\n|\n|\r/', $chunk) ?: [];
        if ($start > 0) {
            array_shift($rows); // first line is probably cut in half
        }
        $rows = array_slice($rows, -$lines);

        $entries = [];
        foreach ($rows as $row) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+[\w-]+\.([A-Z]+):\s?(.*)$/', $row, $m)) {
                continue; // stack trace or continuation line
            }
            if (! in_array($m[2], self::LEVELS, true)) {
                continue;
            }
            $entries[] = [
                'time'    => str_replace('T', ' ', $m[1]),
                'level'   => $m[2],
                'message' => self::clean($m[3]),
            ];
        }

        return ['state' => 'ok', 'file' => basename($path), 'size' => $size, 'entries' => array_reverse($entries)];
    }

    /** First line only, JSON context cut off, secrets masked, at most 300 characters. */
    public static function clean(string $message): string
    {
        // Laravel appends the context as JSON: {"exception":"[object] (...)"} or {"userId":1,...}.
        $message = preg_replace('/\s\{"(?:exception|userId|[a-z_]+)".*$/s', '', $message) ?? $message;

        return mb_strimwidth(self::redact(trim($message)), 0, 300, '...');
    }

    public static function redact(string $text): string
    {
        $patterns = [
            // key=value, key: value, "key":"value" for anything named like a secret
            '/([\w.-]*(?:pass(?:word|wd)?|pwd|secret|token|api[_-]?key|apikey|key|auth(?:orization)?|cookie|session|credential)[\w.-]*)(["\']?)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&"\'}\])]+)/i' => '$1$2$3' . self::MASK,
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 ' . self::MASK,
            '/base64:[A-Za-z0-9+\/=]{16,}/' => self::MASK,
            // user:password@host in connection strings
            '#(://[^:/\s@]+):[^@\s/]+@#' => '$1:' . self::MASK . '@',
            // long hex / base64-ish strings (keys, hashes, signed tokens)
            '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\b/' => self::MASK,
            '/\b[a-f0-9]{32,}\b/i' => self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }
}
