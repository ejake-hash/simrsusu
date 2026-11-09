<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'
import type { KategoriTriase } from '@/modules/pendaftaran/tipeKunjungan'
import { IKON_KATEGORI, LABEL_KATEGORI } from '../triase'

/**
 * Penanda kategori triase: warna token `--triase-*` (PRD 5.3.2, hanya layar IGD/triase) selalu disertai ikon dan teks,
 * jadi tetap terbaca tanpa warna. `null` = belum ditriase; `undefined` = server tidak mengirim triase untuk akun ini
 * (tanpa igd.triase.lihat cakupan rs atau MFA belum ulang), jadi "Tidak tersedia", bukan "Belum ditriase".
 */
defineProps<{ kategori?: KategoriTriase | null }>()
</script>

<template>
  <span v-if="kategori" class="triase" :class="`triase-${kategori}`">
    <Ikon :nama="IKON_KATEGORI[kategori]" :ukuran="14" />{{ LABEL_KATEGORI[kategori] }}
  </span>
  <span v-else-if="kategori === null" class="triase triase-belum">Belum ditriase</span>
  <span v-else class="triase triase-tidak">Tidak tersedia</span>
</template>

<style scoped>
.triase {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 2px var(--space-2);
  border-radius: 999px;
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  line-height: 18px;
  white-space: nowrap;
}
.triase-merah {
  background: var(--triase-merah);
  color: var(--surface);
}
.triase-kuning {
  background: var(--triase-kuning);
  color: var(--text);
}
.triase-hijau {
  background: var(--triase-hijau);
  color: var(--surface);
}
.triase-hitam {
  background: var(--triase-hitam);
  color: var(--surface);
}
.triase-belum {
  border: 1px dashed var(--border-input);
  color: var(--muted);
  font-weight: var(--font-weight-regular);
}
.triase-tidak {
  color: var(--muted);
  font-weight: var(--font-weight-regular);
}
</style>
