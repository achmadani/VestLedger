/**
 * Menyalin dependency JS pihak ketiga dari node_modules ke public/assets/js.
 *
 * Tujuannya agar production (shared hosting) cukup menyajikan file statis hasil
 * build dan TIDAK memerlukan Node.js runtime sama sekali (§35).
 */
import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const files = [
  ['node_modules/alpinejs/dist/cdn.min.js', 'public/assets/js/alpine.min.js'],
];

for (const [from, to] of files) {
  const dest = resolve(root, to);
  await mkdir(dirname(dest), { recursive: true });
  await copyFile(resolve(root, from), dest);
  console.log(`copied ${from} -> ${to}`);
}
