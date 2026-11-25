<?php
// Branding + colour schemes, driven by the 'branding' and 'theme' sections of config.php.
declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/** Built-in colour schemes. Pick one with theme.scheme in config.php. */
function theme_presets(): array
{
    return [
        'trans' => [ // trans flag: blue #5BCEFA, pink #F5A9B8, white
            'light' => ['background' => '#fdf3f6', 'card' => '#ffffff', 'text' => '#1b2a3a', 'muted' => '#5f6b7a', 'accent' => '#5bcefa', 'accent_text' => '#0b2233', 'link' => '#0a6c9e', 'success' => '#21804f', 'border' => '#f5cfd8', 'error_bg' => '#fde8ee', 'error_text' => '#9b2340'],
            'dark'  => ['background' => '#15131b', 'card' => '#211c29', 'text' => '#f6f1f8', 'muted' => '#b3a8bb', 'accent' => '#f5a9b8', 'accent_text' => '#2a1220', 'link' => '#5bcefa', 'success' => '#6fd3a4', 'border' => '#3a3046', 'error_bg' => '#3d1823', 'error_text' => '#ffb8c6'],
        ],
        'warm' => [ // cream + tomato
            'light' => ['background' => '#f4f1ea', 'card' => '#ffffff', 'text' => '#1f2a44', 'muted' => '#6b7280', 'accent' => '#e4572e', 'accent_text' => '#ffffff', 'success' => '#2f855a', 'border' => '#e5e1d8', 'error_bg' => '#fdecea', 'error_text' => '#9b2c1c'],
            'dark'  => ['background' => '#12161f', 'card' => '#1c2230', 'text' => '#eef1f6', 'muted' => '#9aa3b2', 'accent' => '#ff7a50', 'accent_text' => '#1a1a1a', 'success' => '#68d391', 'border' => '#2c3446', 'error_bg' => '#3b1d19', 'error_text' => '#ffb4a6'],
        ],
        'ocean' => [ // cool blues
            'light' => ['background' => '#eef4f8', 'card' => '#ffffff', 'text' => '#0f2a3d', 'muted' => '#5b6b78', 'accent' => '#0b6bcb', 'accent_text' => '#ffffff', 'success' => '#1f7a4d', 'border' => '#d6e2ea', 'error_bg' => '#fdecec', 'error_text' => '#a12626'],
            'dark'  => ['background' => '#0b1620', 'card' => '#13212e', 'text' => '#e8f1f8', 'muted' => '#93a6b5', 'accent' => '#4da3ff', 'accent_text' => '#06121d', 'success' => '#5fd39a', 'border' => '#22364a', 'error_bg' => '#3a1717', 'error_text' => '#ffb3b3'],
        ],
        'forest' => [ // greens
            'light' => ['background' => '#f1f5ef', 'card' => '#ffffff', 'text' => '#1d2b20', 'muted' => '#637064', 'accent' => '#2e7d4f', 'accent_text' => '#ffffff', 'success' => '#2e7d4f', 'border' => '#dbe4d7', 'error_bg' => '#fbeaea', 'error_text' => '#992b2b'],
            'dark'  => ['background' => '#0f1712', 'card' => '#18231b', 'text' => '#e9f2eb', 'muted' => '#98a99c', 'accent' => '#5cc98a', 'accent_text' => '#0b160f', 'success' => '#5cc98a', 'border' => '#273a2c', 'error_bg' => '#3a1a1a', 'error_text' => '#ffb4b4'],
        ],
        'berry' => [ // purple / magenta
            'light' => ['background' => '#f6f1f7', 'card' => '#ffffff', 'text' => '#2a1733', 'muted' => '#6f6175', 'accent' => '#8e2bb5', 'accent_text' => '#ffffff', 'success' => '#2f7d5a', 'border' => '#e6dbe9', 'error_bg' => '#fcebee', 'error_text' => '#9c2340'],
            'dark'  => ['background' => '#150f19', 'card' => '#211828', 'text' => '#f3ecf6', 'muted' => '#a898ae', 'accent' => '#cf7cf2', 'accent_text' => '#1a0f20', 'success' => '#6fd3a4', 'border' => '#36283f', 'error_bg' => '#3d1823', 'error_text' => '#ffb3c4'],
        ],
        'slate' => [ // neutral greys, black accent
            'light' => ['background' => '#f3f4f6', 'card' => '#ffffff', 'text' => '#111827', 'muted' => '#6b7280', 'accent' => '#111827', 'accent_text' => '#ffffff', 'success' => '#15803d', 'border' => '#e5e7eb', 'error_bg' => '#fef2f2', 'error_text' => '#b91c1c'],
            'dark'  => ['background' => '#0b0d10', 'card' => '#16191e', 'text' => '#f3f4f6', 'muted' => '#9ca3af', 'accent' => '#f3f4f6', 'accent_text' => '#0b0d10', 'success' => '#4ade80', 'border' => '#2a2f37', 'error_bg' => '#3b1515', 'error_text' => '#fca5a5'],
        ],
    ];
}

/** config key => CSS custom property used in assets/style.css */
const THEME_VARS = [
    'background'  => '--bg',
    'card'        => '--card',
    'text'        => '--ink',
    'muted'       => '--muted',
    'accent'      => '--accent',
    'accent_text' => '--accent-ink',
    'link'        => '--link',
    'success'     => '--ok',
    'border'      => '--line',
    'error_bg'    => '--alert-bg',
    'error_text'  => '--alert-ink',
];

function theme_setting(string $key, $default = null)
{
    return (config('theme') ?? [])[$key] ?? $default;
}

function branding(string $key, $default = null)
{
    return (config('branding') ?? [])[$key] ?? $default;
}

function app_name(): string
{
    return (string) branding('app_name', config('event_name') . ' Door');
}

/** 'auto' follows the device's light/dark setting; 'light' or 'dark' forces one. */
function theme_mode(): string
{
    $mode = theme_setting('mode', 'auto');
    return in_array($mode, ['auto', 'light', 'dark'], true) ? $mode : 'auto';
}

/** Only allow plain colour values, so config can't inject arbitrary CSS. */
function valid_colour(string $c): bool
{
    return (bool) preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg]+\)|[a-z]{3,20})$/i', trim($c));
}

/** Resolved palette for 'light' or 'dark': preset, then per-colour overrides from config. */
function theme_palette(string $which): array
{
    $presets = theme_presets();
    $scheme = theme_setting('scheme', 'warm');
    $palette = ($presets[$scheme] ?? $presets['warm'])[$which];
    $palette['link'] ??= $palette['accent'];
    foreach ((array) theme_setting($which, []) as $key => $value) {
        if (isset(THEME_VARS[$key]) && is_string($value) && valid_colour($value)) {
            $palette[$key] = trim($value);
        }
    }
    return $palette;
}

function theme_vars_css(array $palette): string
{
    $css = '';
    foreach (THEME_VARS as $key => $var) {
        $css .= "{$var}:{$palette[$key]};";
    }
    return $css;
}

/**
 * Inline <style> that overrides the defaults in assets/style.css.
 *
 * Light colours are the base. Dark colours apply when:
 *   - the visitor picked Dark with the toggle (<html data-theme="dark">), or
 *   - they haven't picked Light, and either config mode is 'dark' or (mode 'auto') their device is in dark mode.
 */
function theme_css(): string
{
    $light = theme_vars_css(theme_palette('light')) . 'color-scheme:light;';
    $dark  = theme_vars_css(theme_palette('dark')) . 'color-scheme:dark;';
    $radius = max(0, min(40, (int) theme_setting('corner_radius', 20)));

    $darkRules = static fn (string $sel): string =>
        "{$sel}{{$dark}}{$sel} .logo-light{display:none}{$sel} .logo-dark{display:inline-block}";

    $css = ":root{{$light}--radius:{$radius}px}.logo-dark{display:none}";
    $css .= $darkRules(':root[data-theme="dark"]');
    switch (theme_mode()) {
        case 'dark':
            $css .= $darkRules(':root:not([data-theme="light"])');
            break;
        case 'auto':
            $css .= '@media (prefers-color-scheme: dark){' . $darkRules(':root:not([data-theme="light"])') . '}';
            break;
    }
    return $css;
}

/** Whether visitors/organisers get the light/dark toggle button (theme.allow_toggle). */
function theme_toggle_enabled(): bool
{
    return (bool) theme_setting('allow_toggle', true);
}

/** The toggle button (wired up by assets/theme.js). */
function theme_toggle_html(string $extraClass = ''): string
{
    if (!theme_toggle_enabled()) {
        return '';
    }
    return '<button type="button" class="theme-toggle ' . h($extraClass) . '" aria-label="Colour theme" title="Colour theme">'
        . '<span class="theme-toggle-icon" aria-hidden="true">🌓</span><span class="theme-toggle-label">Auto</span></button>';
}

/**
 * Tiny script for <head>: applies the saved choice before the page is drawn (no flash of the wrong theme).
 */
function theme_head_script(): string
{
    if (!theme_toggle_enabled()) {
        return '';
    }
    return "<script>try{var t=localStorage.getItem('tigdoor-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t;}catch(e){}</script>";
}

/** Colour of the browser bar on phones. */
function theme_bar_colour(string $which = 'light'): string
{
    return theme_palette($which)['text'];
}

/** Turns a config path into a URL: absolute http(s) URLs as-is, otherwise relative to the app folder. */
function asset_url(string $path, string $root): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_contains($path, '..')) {
        return null; // reject javascript:, data:, path traversal, etc.
    }
    return $root . ltrim($path, '/');
}

/** Logo block shown at the top of every page (empty string if no logo configured). */
function logo_html(string $root): string
{
    $logo = asset_url((string) branding('logo', ''), $root);
    if (!$logo) {
        return '';
    }
    $dark = asset_url((string) branding('logo_dark', ''), $root);
    $alt = (string) branding('logo_alt', config('event_name'));
    $height = max(16, min(240, (int) branding('logo_height', 64)));
    $link = asset_url((string) branding('logo_link', ''), $root);

    $style = 'height:' . $height . 'px';
    $img = '<img src="' . h($logo) . '" alt="' . h($alt) . '" style="' . $style . '"' . ($dark ? ' class="logo-light"' : '') . '>';
    if ($dark) {
        $img .= '<img src="' . h($dark) . '" alt="' . h($alt) . '" style="' . $style . '" class="logo-dark">';
    }
    if ($link) {
        $img = '<a href="' . h($link) . '">' . $img . '</a>';
    }
    return '<div class="brand">' . $img . "</div>\n";
}

/** Optional trans-flag stripe across the top of every page (theme.flag_stripe). */
function flag_stripe_html(): string
{
    return theme_setting('flag_stripe', false) ? '<div class="flag-stripe" aria-hidden="true"></div>' . "\n" : '';
}
