<?php
/**
 * Template function for the live numbers (see LiveCounters). Global namespace on purpose: themes call it directly.
 *
 *   <?php tqk_live('comments_post'); ?>
 *   <?php echo tqk_live('posts_count', ['product'], false); ?>
 */
defined('ABSPATH') || exit;

if (!function_exists('tqk_live')) {
    /**
     * @param string   $source  comments_total | comments_post | users_total | posts_count | meta | a source added with the tqk_live_sources filter
     * @param string[] $args    comments_post: [post id]; posts_count: [post type]; meta: [post id, meta key]
     * @param bool     $echo    print (default) or return the markup
     */
    function tqk_live(string $source, array $args = [], bool $echo = true): string
    {
        if ($source === 'comments_post' && !$args) {
            $args = [(string)get_the_ID()];
        }
        $html = \TQK_WP_CACHE\Optimization\LiveCounters::render($source, array_map('strval', $args));
        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by LiveCounters::render()
        }
        return $html;
    }
}
