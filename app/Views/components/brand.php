<?php
/**
 * Brand mark VestLedger: grafik naik yang bertumpu pada ledger.
 *
 * @var string $class
 * @var bool $showLabel
 */
$class = $class ?? 'w-9 h-9';
$showLabel = $showLabel ?? false;
?>
<span class="inline-flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" fill="none"
         class="shrink-0 <?= esc($class, 'attr') ?>" aria-hidden="true" focusable="false">
        <defs>
            <linearGradient id="vestledger-mark" x1="5" y1="5" x2="35" y2="36" gradientUnits="userSpaceOnUse">
                <stop stop-color="#123C5A"/>
                <stop offset="1" stop-color="#0B263D"/>
            </linearGradient>
        </defs>
        <rect width="40" height="40" rx="12" fill="url(#vestledger-mark)"/>
        <path d="M9 11.5h4.5l4.5 14 4.5-14H27l-5.9 17h-6.2l-5.9-17Z" fill="#D9F99D"/>
        <path d="M26 27.5h5M26 30.5h5M26 33.5h5" stroke="#8ED6C2" stroke-width="1.5" stroke-linecap="round"/>
        <path d="m25 22 2.7-2.7 2.2 2.2 3.1-4" stroke="#8ED6C2" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="33" cy="17.5" r="1.35" fill="#D9F99D"/>
    </svg>
    <?php if ($showLabel): ?>
        <span>
            <span class="block font-semibold leading-tight">VestLedger</span>
            <span class="block text-[11px] text-base-content/60 leading-tight">Portfolio &amp; Accounting</span>
        </span>
    <?php endif; ?>
</span>
