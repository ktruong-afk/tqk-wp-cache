<?php
namespace TQK_WP_CACHE\Cache;

use TQK_WP_CACHE\Core\Config;

defined('ABSPATH') || exit;

/**
 * Daily housekeeping: expired pages, orphaned CSS/JS bundles, stale tag indexes, leftover temp files.
 *
 * A bundle is only deleted once it is older than (page lifespan + 1h) since it was last referenced (AssetCombine touches a bundle
 * whenever a page is built with it). By then no cached page can still point at it, so deleting never causes a 404 on a cached page.
 */
class Maintenance {
    public const HOOK = 'tqk_wp_cache_daily';

    public function init(): void {
        add_action(self::HOOK, [$this, 'run']);
        if (is_admin() && !wp_next_scheduled(self::HOOK)) {
            self::schedule();
        }
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::HOOK);
        }
    }

    /** @return array{pages:int, assets:int, tags:int} */
    public function run(): array {
        $ttl = max(1, (int)Config::instance()->get('cache_lifespan', 10)) * 3600;
        $storage = new DiskStorage();
        $base = $storage->base_dir();
        $result = ['pages' => $storage->delete_expired($ttl), 'assets' => 0, 'tags' => 0];

        $asset_limit = time() - max($ttl + 3600, DAY_IN_SECONDS);
        $result['assets'] = $this->prune($base . '/assets', $asset_limit, static fn($name) => true);
        $result['tags'] = $this->prune($base . '/_tags', time() - 2 * $ttl, static fn($name) => substr($name, -4) === '.idx');
        $this->prune($base, time() - HOUR_IN_SECONDS, static fn($name) => strpos($name, '.tmp.') !== false);
        \TQK_WP_CACHE\Admin\IncidentLog::health_check();   // writes a report into the incident folder when something is wrong
        do_action('tqk_cache_daily');   // add-ons: e.g. expired entries of the disk object cache
        $this->prune($base . '/_locks', time() - HOUR_IN_SECONDS, static fn($name) => substr($name, -5) === '.lock' && strpos($name, 'slot') !== 0);
        $this->prune($base, time() - DAY_IN_SECONDS, static fn($name) => substr($name, -8) === '.nocache');

        return $result;
    }

    private function prune(string $dir, int $older_than, callable $match): int {
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isFile() && $file->getMTime() < $older_than && $match($file->getFilename())) {
                    if (@unlink($file->getPathname())) {
                        $removed++;
                    }
                } elseif ($file->isDir()) {
                    @rmdir($file->getPathname()); // only if empty
                }
            }
        } catch (\Throwable $e) {
            // concurrent purge: next run continues
        }
        return $removed;
    }
}
