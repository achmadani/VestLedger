<?php

namespace Config;

/**
 * Optimization Configuration.
 *
 * NOTE: This class does not extend BaseConfig for performance reasons.
 *       So you cannot replace the property values with Environment Variables.
 *
 * WARNING: Do not use these options when running the app in the Worker Mode.
 */
class Optimize
{
    /**
     * --------------------------------------------------------------------------
     * Config Caching
     * --------------------------------------------------------------------------
     *
     * DIMATIKAN DENGAN SENGAJA — jangan dinyalakan tanpa membaca ini.
     *
     * Cache ini menyimpan objek Config lewat var_export, dan memuatnya kembali
     * dengan BaseConfig::__set_state(), yang menyalin properti satu per satu:
     *
     *     $obj->{$property} = $array[$property];
     *
     * Begitu sebuah properti BARU ditambahkan ke kelas Config, kunci itu tidak
     * ada di cache lama, sehingga nilainya null dan PHP menolaknya:
     * "Cannot assign null to property ... of type string". Aplikasi mati total.
     *
     * Yang membuatnya berbahaya di sini: kegagalan terjadi di dalam Boot,
     * SEBELUM event pre_system — sehingga App\Libraries\DeploymentRefresh, yang
     * seharusnya membersihkan cache basi setiap kali VERSION berubah, tidak
     * pernah sempat berjalan. Server produksi tidak punya shell maupun terminal,
     * jadi satu-satunya jalan keluar adalah menghapus berkas cache lewat File
     * Manager. Ini sudah benar-benar terjadi, pada penambahan satu properti.
     *
     * Harganya murah: pengukuran pada halaman login menunjukkan selisih ~1,4 ms
     * per request (11,4 ms dengan cache, 12,8 ms tanpa). Situs yang mati jauh
     * lebih mahal daripada itu.
     *
     * @see https://codeigniter.com/user_guide/concepts/factories.html#config-caching
     */
    public bool $configCacheEnabled = false;

    /**
     * --------------------------------------------------------------------------
     * File Locator Caching
     * --------------------------------------------------------------------------
     *
     * Ini boleh tetap menyala. Cache locator yang basi memang menyembunyikan
     * berkas baru (view, migrasi, command) — jebakan yang tercatat di
     * docs/STATUS.md §4 — tetapi ia TIDAK mematikan aplikasi, sehingga
     * App\Libraries\DeploymentRefresh masih sempat menghapusnya pada request
     * pertama setelah VERSION berubah.
     *
     * @see https://codeigniter.com/user_guide/concepts/autoloader.html#file-locator-caching
     */
    public bool $locatorCacheEnabled = true;
}
