<script setup lang="ts">
import type { HalamanMasuk } from '@/modules/organisasi/tipe'

/**
 * Panel merek halaman masuk (PRD 5.3.2; tiket 1.15): dipakai AuthLayout dan pratinjau di Pengaturan → Profil rumah
 * sakit → Halaman masuk, jadi tampilan pratinjau sama persis. Dekoratif: disembunyikan dari pembaca layar. Semua teks
 * dirender sebagai teks biasa (interpolasi Vue), tidak pernah sebagai HTML.
 */
defineProps<{
  isi: HalamanMasuk
  namaRs: string | null
  foto: string | null
  motif: string | null
}>()
</script>

<template>
  <aside class="panel-merek" aria-hidden="true" :style="isi.tampilkan_motif && motif ? { '--motif': `url(${motif})` } : undefined">
    <div>
      <span class="tag"><span class="titik"></span>{{ isi.lencana }}</span>
      <p class="judul-merek">
        {{ isi.judul }}<template v-if="isi.judul_sorot">{{ ' ' }}<em>{{ isi.judul_sorot }}</em></template>
      </p>
      <p class="lead-merek">{{ isi.uraian }}</p>
    </div>
    <figure v-if="foto" class="foto">
      <img :src="foto" alt="" width="1600" height="1000" decoding="async" />
      <figcaption v-if="isi.foto_keterangan || namaRs"><span class="titik"></span>{{ isi.foto_keterangan || namaRs }}</figcaption>
    </figure>
    <ul class="poin">
      <li v-for="(f, i) in isi.fitur" :key="i"><b>{{ f.judul }}</b>{{ f.uraian }}</li>
    </ul>
  </aside>
</template>

<style scoped>
.panel-merek {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: var(--space-8);
  padding: 48px;
  border-radius: 20px;
  background: linear-gradient(160deg, var(--panel-merek) 0%, var(--panel-merek-tengah) 55%, var(--panel-merek-akhir) 100%);
  color: var(--surface);
  line-height: 1.5;
}
/* Motif identitas RS (PRD 5.3.2 aturan 4: hanya halaman masuk, antrean, cetak). */
.panel-merek::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: var(--motif, none);
  background-size: 52px 52px;
  opacity: 0.07;
  mask-image: radial-gradient(ellipse at 75% 25%, var(--surface) 25%, transparent 75%);
}
.panel-merek::after {
  content: '';
  position: absolute;
  top: -160px;
  right: -160px;
  width: 520px;
  height: 520px;
  border-radius: 50%;
  background: radial-gradient(circle, color-mix(in srgb, var(--panel-merek-sorot) 45%, transparent), transparent 65%);
}
.panel-merek > * {
  position: relative;
  z-index: 1;
}
.tag {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  max-width: 100%;
  padding: 5px 12px;
  border: 1px solid color-mix(in srgb, var(--surface) 16%, transparent);
  border-radius: 999px;
  color: var(--brand-100);
  font-size: 12px;
  font-weight: var(--font-weight-medium);
}
.titik {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--accent);
}
.judul-merek {
  max-width: 460px;
  margin: 20px 0 14px;
  font-size: 34px;
  font-weight: var(--font-weight-bold);
  line-height: 1.15;
  letter-spacing: -0.02em;
  text-wrap: balance;
  overflow-wrap: anywhere;
}
.judul-merek em {
  font-style: normal;
  color: var(--penekanan-panel);
}
.lead-merek {
  max-width: 440px;
  margin: 0;
  color: var(--brand-100);
  overflow-wrap: anywhere;
}
.foto {
  position: relative;
  flex: 1 1 auto;
  min-height: 200px;
  max-height: 480px;
  margin: 0;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--surface) 14%, transparent);
  border-radius: 16px;
  background: var(--panel-merek-akhir);
  box-shadow: 0 24px 48px -24px var(--panel-merek-akhir);
}
.foto img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: 50% 40%;
}
/* Gelap di bawah agar keterangan terbaca dan foto menyatu dengan panel merek. */
.foto::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, color-mix(in srgb, var(--panel-merek-akhir) 85%, transparent), transparent 45%);
}
.foto figcaption {
  position: absolute;
  z-index: 1;
  left: 18px;
  right: 18px;
  bottom: 14px;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--surface);
  font-size: 12px;
  font-weight: var(--font-weight-medium);
}

.poin {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  margin: 0;
  padding: var(--space-6) 0 0;
  border-top: 1px solid color-mix(in srgb, var(--surface) 12%, transparent);
  list-style: none;
  color: var(--brand-100);
  font-size: 12px;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
.poin b {
  display: block;
  margin-bottom: var(--space-1);
  color: var(--surface);
  font-size: 13px;
}
</style>
