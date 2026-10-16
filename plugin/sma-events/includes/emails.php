<?php
// Plain-text emails from emails/<name>.txt ("#" header lines, "Subject:", body); lines with an empty {{placeholder}} are dropped.

if (!defined('ABSPATH')) {
    exit;
}

const SMA_EMAIL_PLACEHOLDER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/';

function sma_email_template_path($template)
{
    $template = sanitize_key($template);
    $path = SMA_EVENTS_DIR . '/emails/' . $template . '.txt';
    return (string) apply_filters('sma_email_template_path', $path, $template);
}

/** Render a template: ['subject' => string, 'body' => string] or WP_Error. */
function sma_render_email($template, array $vars)
{
    $path = sma_email_template_path($template);
    if (!is_readable($path)) {
        return new WP_Error('sma_no_template', 'Email template not found.', ['status' => 500]);
    }

    $clean = [];
    foreach ($vars as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $clean[(string) $key] = trim(str_replace(["\r", "\n"], ' ', wp_strip_all_tags((string) $value)));
        }
    }
    $clean = apply_filters('sma_email_vars', $clean, $template);

    $lines   = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));
    $subject = '';
    $body    = [];
    $in_head = true;

    foreach ($lines as $line) {
        if ($in_head) {
            if (strpos($line, '#') === 0) {
                continue;
            }
            if (stripos($line, 'Subject:') === 0) {
                $subject = trim(substr($line, 8));
                $in_head = false;
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $in_head = false;
        }

        if (preg_match_all(SMA_EMAIL_PLACEHOLDER, $line, $found)) {
            $empty = false;
            foreach ($found[1] as $name) {
                if (!isset($clean[$name]) || $clean[$name] === '') {
                    $empty = true;
                    break;
                }
            }
            if ($empty) {
                continue;
            }
        }
        $body[] = sma_fill_placeholders($line, $clean);
    }

    $body_text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $body))) . "\n";
    $subject   = trim(preg_replace('/\s+/', ' ', sma_fill_placeholders($subject, $clean)));

    return [
        'subject' => $subject,
        'body'    => $body_text,
    ];
}

/** A template's subject and body with placeholders untouched, for templates FluentBooking fills itself. */
function sma_email_template_raw($template)
{
    $path = sma_email_template_path($template);
    if (!is_readable($path)) {
        return new WP_Error('sma_no_template', 'Email template not found.', ['status' => 500]);
    }

    $lines   = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));
    $subject = '';
    $body    = [];
    $in_head = true;

    foreach ($lines as $line) {
        if ($in_head) {
            if (strpos($line, '#') === 0 || trim($line) === '') {
                continue;
            }
            $in_head = false;
            if (stripos($line, 'Subject:') === 0) {
                $subject = trim(substr($line, 8));
                continue;
            }
        }
        $body[] = $line;
    }

    return [
        'subject' => $subject,
        'body'    => trim(implode("\n", $body)),
    ];
}

function sma_fill_placeholders($text, array $vars)
{
    return preg_replace_callback(SMA_EMAIL_PLACEHOLDER, function ($m) use ($vars) {
        return isset($vars[$m[1]]) ? $vars[$m[1]] : '';
    }, $text);
}

/** Render and send a template with wp_mail: true or WP_Error. */
function sma_send_email($to, $template, array $vars)
{
    $to = sanitize_email((string) $to);
    if (!$to || !is_email($to)) {
        return new WP_Error('sma_bad_recipient', 'No valid email address for this contact.', ['status' => 400]);
    }

    $mail = sma_render_email($template, $vars);
    if (is_wp_error($mail)) {
        return $mail;
    }

    $headers = ['Content-Type: text/plain; charset=UTF-8', 'X-SMA-Template: ' . sanitize_key($template)];
    $from_address = sanitize_email((string) apply_filters('sma_email_from_address', ''));
    if ($from_address) {
        $headers[] = 'From: ' . sma_email_from_name() . ' <' . $from_address . '>';
    }
    $headers = apply_filters('sma_email_headers', $headers, $template, $to);

    $from_name = 'sma_email_from_name';
    add_filter('wp_mail_from_name', $from_name, 20);
    $sent = wp_mail($to, $mail['subject'], $mail['body'], $headers);
    remove_filter('wp_mail_from_name', $from_name, 20);

    if (!$sent) {
        error_log('sma: wp_mail returned false for template ' . sanitize_key($template));
        return new WP_Error('sma_mail_failed', 'The email could not be sent.', ['status' => 500]);
    }
    return true;
}

function sma_email_from_name()
{
    return (string) apply_filters('sma_email_from_name_value', 'So Many Angels');
}

/** "Sam" → "Sam's"; "Chris" → "Chris'"; empty → "your child's". */
function sma_possessive($first_name)
{
    $first_name = trim((string) $first_name);
    if ($first_name === '') {
        return "your child's";
    }
    return substr($first_name, -1) === 's' ? $first_name . "'" : $first_name . "'s";
}
