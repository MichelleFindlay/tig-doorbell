<?php
// TIG Door — example configuration.
//
// Copy this file to config.php and edit it:   cp config.example.php config.php
// config.php is ignored by git, so your settings and admin access word stay private.
//
// Any option you leave out of config.php falls back to the value in this file.
return [
    // ------------------------------------------------------------ event

    // Shown to visitors and in notifications. Used when no event from the organiser
    // "Events" page is on right now.
    'event_name'    => 'TIG',
    'event_tagline' => 'Knock knock! Let the organisers know you have arrived.',

    // Events added on the organiser "Events" page show on the door page from this many
    // minutes before they start until this many minutes after they end.
    'door_opens_before' => 60,
    'door_closes_after' => 30,

    // When no event is on: true still lets visitors knock for event_name above;
    // false shows "no event right now" with the next session date and doesn't let them knock.
    'allow_knock_without_event' => true,

    'timezone' => 'Europe/London',

    // ------------------------------------------------------------ security

    // Admin access word: needed to create the first organiser account and to add/remove organisers.
    // CHANGE THIS. Setup refuses to run while it is empty, shorter than 8 characters or 'change-me'.
    // Changing it later locks any unlocked Team pages.
    'admin_access_word' => 'change-me',

    // Contact for push services (required by the Web Push spec). Use a real mailbox or https URL.
    'vapid_subject' => 'mailto:organisers@example.com',

    // ------------------------------------------------------------ look & feel

    'branding' => [
        // Name used in page titles, the home-screen app and notifications.
        'app_name' => 'TIG Door',

        // Logo shown at the top of every page. Upload the image (e.g. into assets/) and give its path
        // relative to this folder, or a full https:// URL. Leave '' for no logo.
        'logo'        => '',            // e.g. 'assets/logo.png'
        'logo_dark'   => '',            // optional version for dark mode, e.g. 'assets/logo-white.png'
        'logo_alt'    => 'TIG',         // text for screen readers
        'logo_height' => 64,            // pixels (16–240)
        'logo_link'   => '',            // optional link when the logo is tapped, e.g. 'https://example.com'

        // Browser-tab icon. '' uses the built-in door icon. e.g. 'assets/favicon.png'
        'favicon' => '',

        // Show the big emoji icons (🚪 🔑) above page headings. Set false if your logo is enough.
        'show_icons' => true,
    ],

    'theme' => [
        // Ready-made colour scheme: 'trans' (trans flag blue, pink & white), 'warm' (cream + orange),
        // 'ocean' (blues), 'forest' (greens), 'berry' (purples) or 'slate' (neutral greys).
        'scheme' => 'trans',

        // Show a thin trans flag stripe (blue / pink / white / pink / blue) across the top of every page.
        'flag_stripe' => true,

        // Starting look: 'auto' follows each person's phone setting; 'light' or 'dark' sets the default.
        'mode' => 'auto',

        // Show a light/dark toggle button on every page (visitor and organiser). Each person's
        // choice (Auto / Light / Dark) is remembered on their own device.
        'allow_toggle' => true,

        // Card and button roundness in pixels (0 = square corners, max 40).
        'corner_radius' => 20,

        // Override individual colours of the chosen scheme (hex like '#e4572e', rgb(), hsl() or a CSS colour name).
        // Leave empty to use the scheme as-is. Available keys:
        //   background, card, text, muted, accent, accent_text, link, success, border, error_bg, error_text
        // The 'trans' scheme uses flag blue #5BCEFA for buttons in light mode and flag pink #F5A9B8 in dark mode.
        'light' => [
            // 'accent'      => '#5bcefa',   // buttons, focus rings (trans flag blue)
            // 'accent_text' => '#0b2233',   // text on buttons
            // 'link'        => '#0a6c9e',   // links (a deeper blue so it's readable on white)
            // 'background'  => '#fdf3f6',   // page background (pale trans pink)
        ],
        'dark' => [
            // 'accent' => '#f5a9b8',        // trans flag pink
            // 'link'   => '#5bcefa',        // trans flag blue
        ],
    ],

    // ------------------------------------------------------------ advanced

    // Show full error messages on the page instead of a generic 500 error. Only switch this on
    // while fixing a problem, as it can reveal server paths.
    'debug' => false,

    // SQLite database. Leave null to auto-create one in data/ with a random, unguessable name.
    // If your host lets you, a path outside the web folder is even safer, e.g. '/home/you/tig-door.sqlite'.
    'db_path' => null,

    // Set true only if running behind a reverse proxy that sets X-Forwarded-For / X-Forwarded-Proto.
    'trust_proxy' => false,

    // Visitors: max knocks per IP inside the window (seconds). Kept generous because
    // guests on venue Wi-Fi (or a shared door tablet) all share one IP.
    'knock_rate_limit'  => 20,
    'knock_rate_window' => 600,

    // Seconds before a visitor may "knock again" if nobody has answered.
    'ring_again_after' => 60,

    // Knocks older than this are deleted automatically.
    'retention_days' => 30,

    // Push endpoints must be on one of these hosts (suffix match). Guards against SSRF.
    'push_hosts' => [
        'fcm.googleapis.com',            // Chrome, Edge (Android), Brave, Samsung, Opera
        'push.services.mozilla.com',     // Firefox
        'push.apple.com',                // Safari / iOS (web.push.apple.com)
        'notify.windows.com',            // Edge (Windows)
    ],
];
