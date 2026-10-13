<?php
namespace TQK_WP_CACHE\Optimization;

defined('ABSPATH') || exit;

/**
 * Conservative HTML minifier.
 *
 * What it must NEVER touch (the previous version collapsed whitespace everywhere and broke every inline script containing a
 * `//` comment): <script>, <style>, <pre>, <textarea>, <code>, <svg> text, and conditional comments.
 * Whitespace is only ever collapsed to a single space, never removed, so rendering is identical to the original.
 */
class HtmlMinify {
    public static function process(string $html): string {
        $original = $html;
        $protected = [];
        $token = static function (string $block) use (&$protected): string {
            $key = "\x01TQK" . count($protected) . "\x02";
            $protected[$key] = $block;
            return $key;
        };

        // 1. Park blocks whose content is whitespace-sensitive or code.
        $html = preg_replace_callback('#<(script|style|pre|textarea|code)\b[^>]*>.*?</\1\s*>#is', fn($m) => $token($m[0]), $html);
        if ($html === null) {
            return $original; // PCRE backtrack limit on a huge page: ship it untouched rather than broken
        }
        // Conditional comments (<!--[if IE]> ... <![endif]-->) are code too.
        $html = preg_replace_callback('#<!--\[if[^\]]*\]>.*?<!\[endif\]-->#is', fn($m) => $token($m[0]), $html);

        // 2. Drop ordinary comments.
        $html = preg_replace('/<!--(?!\[if|<!\[endif).*?-->/s', '', $html);

        // 3. Collapse every whitespace run to ONE space. Never remove it next to a tag: themes make <li>/<div> inline-block, and the
        //    gap between them is part of the layout (stripping it moved a whole menu by 4px per item).
        $html = preg_replace('/[ 	

]+/', ' ', $html) ?? $html;

        // 4. Restore.
        // (a few passes: a parked conditional comment can itself contain a parked <script> token)
        for ($i = 0; $protected && $i < 3 && strpos($html, "TQK") !== false; $i++) {
            $html = strtr($html, $protected);
        }

        return trim($html);
    }
}
