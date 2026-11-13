<script setup lang="ts">
import Lencana from '@/design-system/components/Lencana.vue'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { LABEL_PENANDA, NADA_PENANDA, TEKS_TANPA_RUJUKAN, teksBatasKritis, teksNilai, teksRujukan } from '../lab'
import type { HasilLab, NilaiHasilLab } from '../tipe'

/**
 * Satu hasil lab (tiket 2.31): nilai + satuan, penanda berteks dari server (tidak dihitung di klien), rentang rujukan dan
 * batas kritis yang dipakai server, peringatan tanpa nilai rujukan, lalu adendum di bawah hasil asli (hasil asli tetap
 * terlihat). `pelaku` = tampilkan siapa dan kapan memvalidasi/mengotorisasi (linimasa).
 */
withDefaults(defineProps<{ hasil: HasilLab; pelaku?: boolean }>(), { pelaku: false })

const bagian = (h: NilaiHasilLab) => [teksRujukan(h), teksBatasKritis(h)].filter(Boolean).join(' · ')
</script>

<template>
  <div class="hasil-lab">
    <div class="nilai-baris">
      <span v-if="hasil.adendum.length" class="label-kecil">Hasil asli</span>
      <b class="nilai">{{ teksNilai(hasil) }}</b>
      <Lencana v-if="hasil.penanda" :jenis="NADA_PENANDA[hasil.penanda]">{{ LABEL_PENANDA[hasil.penanda] }}</Lencana>
    </div>
    <span v-if="bagian(hasil)" class="ket">{{ bagian(hasil) }}</span>
    <span v-if="hasil.peringatan === 'nilai_rujukan_tidak_ada'" class="peringatan">
      <Lencana jenis="info">{{ TEKS_TANPA_RUJUKAN }}</Lencana>
    </span>
    <span v-if="hasil.catatan" class="ket">Catatan: {{ hasil.catatan }}</span>
    <template v-if="pelaku">
      <span v-if="hasil.divalidasi_pada" class="ket">Divalidasi {{ hasil.divalidasi_oleh?.nama ?? 'petugas tidak tercatat' }}, {{ formatTanggalWaktu(hasil.divalidasi_pada) }}</span>
      <span v-if="hasil.diotorisasi_pada" class="ket">Diotorisasi Sp.PK {{ hasil.diotorisasi_oleh?.nama ?? 'tidak tercatat' }}, {{ formatTanggalWaktu(hasil.diotorisasi_pada) }}</span>
    </template>
    <ol v-if="hasil.adendum.length" class="adendum" :aria-label="`Adendum ${hasil.nama}`">
      <li v-for="a in hasil.adendum" :key="a.id">
        <div class="nilai-baris">
          <Lencana jenis="merek" tanpa-ikon>Adendum</Lencana>
          <b class="nilai">{{ teksNilai(a) }}</b>
          <Lencana v-if="a.penanda" :jenis="NADA_PENANDA[a.penanda]">{{ LABEL_PENANDA[a.penanda] }}</Lencana>
        </div>
        <span v-if="bagian(a)" class="ket">{{ bagian(a) }}</span>
        <span v-if="a.peringatan === 'nilai_rujukan_tidak_ada'" class="peringatan">
          <Lencana jenis="info">{{ TEKS_TANPA_RUJUKAN }}</Lencana>
        </span>
        <span class="ket">{{ a.dibuat_oleh?.nama ?? 'Petugas tidak tercatat' }}, {{ formatTanggalWaktu(a.dibuat_pada) }}</span>
        <span class="ket">Alasan: {{ a.alasan }}</span>
      </li>
    </ol>
  </div>
</template>

<style scoped>
.hasil-lab {
  display: grid;
  gap: var(--space-1);
  min-width: 0;
}
.nilai-baris {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.nilai {
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
.label-kecil,
.ket {
  font-size: 12px;
  color: var(--muted);
}
.ket {
  display: block;
  overflow-wrap: anywhere;
}
.peringatan {
  display: block;
}
.adendum {
  display: grid;
  gap: var(--space-2);
  margin: var(--space-1) 0 0;
  padding: 0 0 0 var(--space-3);
  border-left: 2px solid var(--border-input);
  list-style: none;
}
.adendum li {
  display: grid;
  gap: var(--space-1);
}
</style>
