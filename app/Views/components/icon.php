<?php
/**
 * Inline SVG icon set.
 *
 * Sengaja inline (bukan icon font / sprite eksternal) agar tidak ada request
 * tambahan dan tidak ada dependency pihak ketiga (§34).
 *
 * @var string $name
 * @var string $class
 */
$name  = $name ?? 'dot';
$class = $class ?? 'w-5 h-5';

$paths = [
    'dashboard'   => '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>',
    'chart'       => '<path d="M3 3v18h18v-2H5V3H3zm4 12h2v4H7v-4zm4-6h2v10h-2V9zm4 3h2v7h-2v-7zm4-6h2v13h-2V6z"/>',
    'transaction' => '<path d="M7 7h10l-3-3 1.4-1.4L21 8l-5.6 5.4L14 12l3-3H7V7zm10 10H7l3 3-1.4 1.4L3 16l5.6-5.4L10 12l-3 3h10v2z"/>',
    'book'        => '<path d="M6 2h13a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3zm0 2a1 1 0 0 0-1 1v11.17c.31-.11.65-.17 1-.17h12V4H6zm0 14a1 1 0 0 0 0 2h12v-2H6z"/>',
    'report'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13h8v2H8v-2zm0 4h8v2H8v-2z"/>',
    'database'    => '<path d="M12 2c4.42 0 8 1.34 8 3v14c0 1.66-3.58 3-8 3s-8-1.34-8-3V5c0-1.66 3.58-3 8-3zm0 2c-3.5 0-6 .96-6 1s2.5 1 6 1 6-.96 6-1-2.5-1-6-1zm6 4.6C16.5 9.5 14.4 10 12 10s-4.5-.5-6-1.4v3c0 .04 2.5 1 6 1s6-.96 6-1v-3zm0 6C16.5 15.5 14.4 16 12 16s-4.5-.5-6-1.4V19c0 .04 2.5 1 6 1s6-.96 6-1v-4.4z"/>',
    'shield'      => '<path d="M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5l8-3zm0 2.2L6 6.4V11c0 3.9 2.5 7.4 6 8.9 3.5-1.5 6-5 6-8.9V6.4l-6-2.2z"/>',
    'logout'      => '<path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5V3zm6.7 4.3L15.3 8.7 17.6 11H9v2h8.6l-2.3 2.3 1.4 1.4L21.4 12l-4.7-4.7z"/>',
    'palette'     => '<path d="M12 3a9 9 0 0 0 0 18c.83 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1a1.5 1.5 0 0 1 1.1-2.5H16a5 5 0 0 0 5-5c0-4.42-4.03-8-9-8zm-5.5 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm3-4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm5 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm3.5 4a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/>',
    'menu'        => '<path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>',
    'user'        => '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/>',
    'check'       => '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>',
    'warning'     => '<path d="M12 2 1 21h22L12 2zm1 14h-2v2h2v-2zm0-6h-2v5h2v-5z"/>',
    'info'        => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>',
    'error'       => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm5 13.6L15.6 17 12 13.4 8.4 17 7 15.6 10.6 12 7 8.4 8.4 7 12 10.6 15.6 7 17 8.4 13.4 12 17 15.6z"/>',
    'lock'        => '<path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V11a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5zm0 2a3 3 0 0 1 3 3v3H9V6a3 3 0 0 1 3-3z"/>',
    'clock'       => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 10.4V6h-2v7.4l5 3 1-1.7-4-2.3z"/>',
    'dot'         => '<circle cx="12" cy="12" r="4"/>',
];
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
     class="<?= esc($class, 'attr') ?>" aria-hidden="true" focusable="false"><?= $paths[$name] ?? $paths['dot'] ?></svg>
