<?php
/**
 * Peringatan saldo kas negatif.
 *
 * Saldo kas negatif TIDAK diblokir sistem — aplikasi ini dipakai untuk
 * pencatatan dan transaksi kerap dimasukkan mundur, sehingga urutan input tidak
 * selalu sama dengan urutan kejadian. Namun saldo RDN yang benar-benar negatif
 * hampir selalu berarti ada transaksi yang belum dicatat, jadi ditandai jelas.
 *
 * @var list<array{securities_code:string, account_label:string, balance:\App\ValueObjects\Money}> $accounts
 */
$accounts = $accounts ?? [];
?>
<?php if ($accounts !== []): ?>
    <div class="alert alert-warning mb-4">
        <?= component('icon', ['name' => 'warning', 'class' => 'w-5 h-5 shrink-0']) ?>
        <div class="text-sm">
            <p class="font-medium">
                Saldo kas negatif pada <?= count($accounts) ?> rekening.
            </p>
            <ul class="text-xs opacity-90 mt-1 space-y-0.5">
                <?php foreach ($accounts as $account): ?>
                    <li>
                        <span class="font-mono"><?= esc($account['securities_code']) ?></span>
                        &middot; <?= esc($account['account_label']) ?>:
                        <span class="num font-medium"><?= esc(fmt_rupiah($account['balance']->toFloat())) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs opacity-80 mt-1">
                Ini tidak menghalangi pencatatan — transaksi memang boleh dimasukkan mundur.
                Namun saldo RDN yang benar-benar negatif biasanya berarti ada top up atau
                penjualan yang belum dicatat.
            </p>
        </div>
    </div>
<?php endif; ?>
