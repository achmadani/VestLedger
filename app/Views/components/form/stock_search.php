<?php
/**
 * Kotak ketik-cari saham.
 *
 * Dengan hampir seribu emiten, dropdown biasa tidak lagi dapat dipakai, dan
 * mengirim seluruh daftar ke browser berarti memuat ratusan kilobyte pada
 * setiap pembukaan form. Pencarian karenanya dilakukan di server dan hasilnya
 * dibatasi (§34).
 *
 * Yang dikirim ke server tetap `stock_id` lewat hidden input, sehingga
 * validasi di belakang tidak berubah sama sekali.
 *
 * @var string $name  nama field id saham
 * @var string $model properti Alpine induk yang menampung id terpilih
 */
$name  = $name ?? 'stock_id';
$model = $model ?? 'stockId';
$id    = 'f-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
?>
<div class="form-control w-full" x-data="stockSearch()">
    <label class="label pb-1" for="<?= esc($id, 'attr') ?>">
        <span class="label-text font-medium">Saham <span class="text-error" aria-hidden="true">*</span></span>
    </label>

    <div class="relative">
        <input type="text"
               id="<?= esc($id, 'attr') ?>"
               x-model="term"
               @input.debounce.250ms="search()"
               @focus="if (results.length) open = true"
               @keydown.escape="open = false"
               @keydown.arrow-down.prevent="move(1)"
               @keydown.arrow-up.prevent="move(-1)"
               @keydown.enter.prevent="choose(results[highlight])"
               autocomplete="off"
               placeholder="Ketik kode saham, misalnya BBCA"
               class="input input-bordered w-full uppercase"
               :class="selected ? 'input-success' : ''">

        <input type="hidden" name="<?= esc($name, 'attr') ?>" :value="selected?.id ?? ''">

        <div x-show="open && results.length" x-cloak @click.outside="open = false"
             class="absolute z-50 mt-1 w-full max-h-72 overflow-y-auto bg-base-100 border border-base-300 rounded-box shadow-lg">
            <template x-for="(row, i) in results" :key="row.id">
                <button type="button" @click="choose(row)"
                        :class="i === highlight ? 'bg-base-200' : ''"
                        class="w-full text-left px-3 py-2 hover:bg-base-200 border-b border-base-200 last:border-0">
                    <span class="font-mono font-semibold text-sm" x-text="row.ticker"></span>
                    <span class="text-sm" x-text="' — ' + row.company_name"></span>
                    <span class="block text-[11px] text-base-content/50" x-text="row.sector || ''"></span>
                </button>
            </template>
        </div>
    </div>

    <p class="text-xs mt-1" :class="selected ? 'text-success' : 'text-base-content/60'"
       x-text="selected ? (selected.ticker + ' — ' + selected.company_name) : 'Ketik minimal dua huruf untuk mencari.'"></p>
</div>
