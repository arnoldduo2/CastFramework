<?php

declare(strict_types=1);

namespace Cast\Core\Maintenance;

use Cast\Contracts\MaintenanceStore;
use Cast\Http\Request;

/**
 * Maintenance mode: down now, or scheduled with a countdown. A secret (header
 * `X-Maintenance-Secret`, query `maintenance_secret`, or the `cast_maintenance` cookie) lets someone through.
 */
final class MaintenanceManager
{
    public function __construct(private MaintenanceStore $store, private ?\Closure $canBypass = null) {}

    /** @return array{active: bool, scheduled: bool, seconds_remaining: int, target_timestamp: int, message: string, retry_after: int} */
    public function status(): array
    {
        $config = $this->store->read();
        $active = !empty($config['active']);
        $scheduled = !empty($config['scheduled']);
        $target = (int) ($config['target_timestamp'] ?? 0);
        $remaining = 0;

        if ($scheduled && $target > time()) {
            $remaining = $target - time();
        } elseif ($scheduled) {
            // countdown finished: maintenance is now active
            $active = true;
            $scheduled = false;
            $config['active'] = true;
            $config['scheduled'] = false;
            $this->store->write($config);
        }

        return [
            'active' => $active,
            'scheduled' => $scheduled,
            'seconds_remaining' => $remaining,
            'target_timestamp' => $target,
            'message' => (string) ($config['message'] ?? 'We are performing scheduled maintenance. Please check back shortly.'),
            'retry_after' => (int) ($config['retry_after'] ?? 0),
        ];
    }

    public function isActive(): bool
    {
        return $this->status()['active'];
    }

    public function down(string $message = '', ?string $secret = null, int $retryAfter = 0): void
    {
        $this->store->write([
            'active' => true,
            'scheduled' => false,
            'message' => $message,
            'secret' => $secret ? hash('sha256', $secret) : null,
            'retry_after' => $retryAfter,
            'since' => time(),
        ]);
    }

    /** Go down automatically in `$seconds` (at least 5). */
    public function schedule(int $seconds = 60, string $message = ''): void
    {
        $this->store->write([
            'active' => false,
            'scheduled' => true,
            'target_timestamp' => time() + max(5, $seconds),
            'message' => $message !== '' ? $message : "Maintenance starts in $seconds seconds. Please save your work.",
        ]);
    }

    public function up(): void
    {
        $this->store->clear();
    }

    /** True when this request may pass through maintenance mode. */
    public function allows(Request $request): bool
    {
        if ($this->canBypass !== null && ($this->canBypass)($request)) return true;

        $hash = $this->store->read()['secret'] ?? null;
        if (!$hash) return false;

        $given = (string) ($request->header('X-Maintenance-Secret') ?? $request->query('maintenance_secret') ?? '');
        if ($given !== '' && hash_equals($hash, hash('sha256', $given))) return true;

        return hash_equals($hash, (string) $request->cookie('cast_maintenance', ''));
    }
}
