<?php
namespace TQK_WP_CACHE\Admin;

defined('ABSPATH') || exit;

/**
 * "Report a problem" form: the customer describes what went wrong and the message (optionally with the support report attached) is
 * e-mailed to support. Nothing is sent without a click on the button, and the form says what is sent.
 */
class Support {
    public const ACTION  = 'tqk_cache_support_send';
    public const DEFAULT_TO = 'roo.adm@gmail.com';

    public static function to(): string
    {
        $to = (string)apply_filters('tqk_cache_support_email', self::DEFAULT_TO);
        return is_email($to) ? $to : self::DEFAULT_TO;
    }

    public function init(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'ajax']);
    }

    /**
     * Validate what the form posted. Pure (no WordPress calls besides sanitizers passed in), so it can be unit tested.
     * @return array{ok:bool, error?:string, name?:string, email?:string, message?:string}
     */
    public static function clean(array $post): array
    {
        $name = trim(strip_tags((string)($post['name'] ?? '')));
        $email = trim((string)($post['email'] ?? ''));
        $message = trim(strip_tags((string)($post['message'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'email'];
        }
        $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($len < 10) {
            return ['ok' => false, 'error' => 'short'];
        }
        if ($len > 5000) {
            return ['ok' => false, 'error' => 'long'];
        }
        return ['ok' => true, 'name' => substr($name, 0, 80), 'email' => $email, 'message' => $message];
    }

    public function ajax(): void
    {
        check_ajax_referer('tqk_cache_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tqk-wp-cache')]);
        }
        $lock = 'tqk_cache_support_' . get_current_user_id();
        if (get_transient($lock)) {
            wp_send_json_error(['message' => __('A message was just sent. Please wait a minute before sending another one.', 'tqk-wp-cache')]);
        }
        $in = self::clean(wp_unslash($_POST));
        if (!$in['ok']) {
            $texts = [
                'email' => __('The e-mail address is not valid.', 'tqk-wp-cache'),
                'short' => __('Please describe the problem in a few words (at least 10 characters).', 'tqk-wp-cache'),
                'long'  => __('The message is too long (5000 characters at most).', 'tqk-wp-cache'),
            ];
            wp_send_json_error(['message' => $texts[$in['error']] ?? __('Please check the form.', 'tqk-wp-cache')]);
        }

        $host = (string)wp_parse_url(home_url(), PHP_URL_HOST);
        $body = $in['message'] . "\n\n---\n"
            . 'Site: ' . $host . "\n"
            . 'Plugin: ' . (defined('TQK_WP_CACHE_VERSION') ? TQK_WP_CACHE_VERSION : '?') . "\n"
            . 'From: ' . ($in['name'] !== '' ? $in['name'] : '-') . ' <' . ($in['email'] !== '' ? $in['email'] : '-') . ">\n"
            . 'Incident folder: ' . IncidentLog::url() . "\n";

        $attachments = [];
        $tmp = '';
        if (!empty($_POST['attach'])) {
            $tmp = wp_tempnam('tqk-report');
            if ($tmp && @file_put_contents($tmp, wp_json_encode((new Diagnostics())->collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                $named = dirname($tmp) . '/tqk-report-' . $host . '-' . gmdate('Ymd-His') . '.json';
                $attachments[] = @rename($tmp, $named) ? $named : $tmp;
                $tmp = $attachments[0];
            }
        }
        $headers = [];
        if ($in['email'] !== '') {
            $headers[] = 'Reply-To: ' . ($in['name'] !== '' ? str_replace(['<', '>', ',', "\r", "\n"], '', $in['name']) . ' ' : '') . '<' . $in['email'] . '>';
        }
        $subject = '[TQK WP Cache] ' . $host . ' - ' . mb_substr(preg_replace('/\s+/', ' ', $in['message']), 0, 60);
        $sent = wp_mail(self::to(), $subject, $body, $headers, $attachments);
        if ($tmp !== '') {
            @unlink($tmp);
        }
        if (!$sent) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: support e-mail address */
                __('This server could not send e-mail. Download the support report and send it with your message to %s.', 'tqk-wp-cache'),
                self::to()
            ), 'mailto' => self::to()]);
        }
        set_transient($lock, 1, MINUTE_IN_SECONDS);
        wp_send_json_success(['message' => __('Thank you. Your message was sent to support.', 'tqk-wp-cache')]);
    }
}
