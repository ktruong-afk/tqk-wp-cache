<?php
namespace TQK_WP_CACHE\Core;

use TQK_WP_CACHE\Cache\Dropin;
use TQK_WP_CACHE\Cache\DiskStorage;
use TQK_WP_CACHE\Cache\Maintenance;

defined('ABSPATH') || exit;

class Deactivator {
    public static function deactivate(): void {
        // Remove dropin and WP_CACHE definition
        Dropin::uninstall();

        // Clear cached files (including bundles: nothing will reference them any more)
        $storage = new DiskStorage();
        $storage->purge_all(true);

        do_action('tqk_cache_deactivating');   // add-ons clean up their own drop-ins

        Maintenance::unschedule();
    }
}
