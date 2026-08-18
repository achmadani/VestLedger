<?php
/**
 * Tabel data server-side.
 *
 * Komponen ini hanya merender; paging/sorting/filter dikerjakan di query (§32, §34)
 * sehingga dataset besar tidak pernah dikirim seluruhnya ke browser.
 *
 * @var list<array{label:string, align?:string, class?:string, render?:callable, key?:string}> $columns
 * @var iterable    $rows
 * @var string|null $emptyTitle
 * @var string|null $emptyDescription
 * @var bool        $zebra
 * @var bool        $compact
 */
$columns          = $columns ?? [];
$rows             = $rows ?? [];
$emptyTitle       = $emptyTitle ?? 'Belum ada data';
$emptyDescription = $emptyDescription ?? null;
$zebra            = $zebra ?? true;
$compact          = $compact ?? false;

$rowsArray = is_array($rows) ? $rows : iterator_to_array($rows);
?>
<?php if ($rowsArray === []): ?>
    <?= component('empty_state', ['title' => $emptyTitle, 'description' => $emptyDescription]) ?>
<?php else: ?>
    <div class="overflow-x-auto">
        <table class="table <?= $zebra ? 'table-zebra' : '' ?> <?= $compact ? 'table-xs' : 'table-sm' ?>">
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th class="<?= ($col['align'] ?? 'left') === 'right' ? 'num' : '' ?> <?= esc($col['class'] ?? '', 'attr') ?>">
                            <?= esc($col['label'] ?? '') ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rowsArray as $row): ?>
                    <tr class="hover">
                        <?php foreach ($columns as $col): ?>
                            <td class="<?= ($col['align'] ?? 'left') === 'right' ? 'num' : '' ?> <?= esc($col['class'] ?? '', 'attr') ?>">
                                <?php if (isset($col['render']) && is_callable($col['render'])): ?>
                                    <?= $col['render']($row) ?>
                                <?php else: ?>
                                    <?= esc((string) (is_array($row) ? ($row[$col['key'] ?? ''] ?? '') : ($row->{$col['key'] ?? ''} ?? ''))) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
