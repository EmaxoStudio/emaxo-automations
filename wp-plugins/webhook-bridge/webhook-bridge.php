<?php
/**
 * Plugin Name: Webhook Bridge (HMAC)
 * Description: Secure inbound webhook endpoints for automations (n8n/Make/Zapier). Uses HMAC signature.
 * Version: 0.1.0
 * Author: Engel Automations
 */

if (!defined('ABSPATH')) exit;

function engel_webhook_secret(): string {
    if (defined('ENGEL_WEBHOOK_SECRET') && is_string(ENGEL_WEBHOOK_SECRET) && ENGEL_WEBHOOK_SECRET !== '') {
        return ENGEL_WEBHOOK_SECRET;
    }
    $env = getenv('ENGEL_WEBHOOK_SECRET');
    return is_string($env) ? $env : '';
}

function engel_hash_equals(string $a, string $b): bool {
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
    return $res === 0;
}

add_action('rest_api_init', function () {
    register_rest_route('engel/v1', '/webhook/(?P<topic>[a-zA-Z0-9_-]+)', [
        'methods'  => 'POST',
        'callback' => 'engel_webhook_handler',
        'permission_callback' => '__return_true', // HMAC handles auth
        'args' => [
            'topic' => ['required' => true],
        ],
    ]);
});

function engel_webhook_handler(WP_REST_Request $request) {
    $secret = engel_webhook_secret();
    if ($secret === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'server_not_configured'], 500);
    }

    $topic = (string) $request->get_param('topic');
    $timestamp = (string) $request->get_header('x_engel_timestamp');
    $signature = (string) $request->get_header('x_engel_signature');

    if ($timestamp === '' || $signature === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'missing_headers'], 401);
    }

    if (!ctype_digit($timestamp)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_timestamp'], 401);
    }

    $ts = (int) $timestamp;
    $now = time();
    if (abs($now - $ts) > 300) {
        return new WP_REST_Response(['ok' => false, 'error' => 'timestamp_out_of_range'], 401);
    }

    $raw = (string) $request->get_body();
    $msg = $timestamp . '.' . $raw;

    $expected = hash_hmac('sha256', $msg, $secret);
    if (!engel_hash_equals($expected, $signature)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 401);
    }

    $data = [
        'ok' => true,
        'topic' => $topic,
        'received_at' => gmdate('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'body' => json_decode($raw, true),
        'raw' => $raw,
    ];

    do_action('engel_webhook_received', $topic, $data, $request);

    return new WP_REST_Response($data, 200);
}
