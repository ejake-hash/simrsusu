<script setup lang="ts">
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Tujuan menu yang modulnya belum dibangun (PRD 10.4). Hanya terjangkau di instalasi pengembangan
 * atau peragaan: di produksi server tidak mengirim menu berstatus "segera".
 */
defineProps<{ judul: string; induk?: string | null; fase: number }>()
</script>

<template>
  <section class="kartu" aria-labelledby="judul-segera">
    <span class="ikon"><Ikon nama="jam" :ukuran="24" /></span>
    <p class="penanda">Segera hadir</p>
    <h1 id="judul-segera">
      <span v-if="induk" class="induk">{{ induk }} · </span>{{ judul }}
    </h1>
    <p class="keterangan">Halaman ini sedang dibangun dan direncanakan tersedia pada fase {{ fase }}.</p>
    <RouterLink to="/" class="tombol">
      <Ikon nama="kembali" :ukuran="16" />
      Kembali ke Beranda
    </RouterLink>
  </section>
</template>

<style scoped>
.kartu {
  max-width: 560px;
  margin: var(--space-8) auto;
  padding: var(--space-8) var(--space-6);
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
  text-align: center;
}
.ikon {
  display: inline-grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: var(--radius-card);
  background: var(--brand-50);
  color: var(--brand-600);
}
.penanda {
  margin: var(--space-4) 0 var(--space-1);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--subtle);
}
h1 {
  margin: 0;
  font-size: var(--text-title);
  line-height: 1.25;
}
.induk {
  color: var(--muted);
  font-weight: var(--font-weight-medium);
}
.keterangan {
  margin: var(--space-2) 0 var(--space-6);
  color: var(--muted);
}
.tombol {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-input);
  background: var(--brand-600);
  color: var(--surface);
  font-weight: var(--font-weight-semibold);
  text-decoration: none;
}
.tombol:hover {
  background: var(--brand-700);
}
.tombol:focus-visible {
  outline: none;
  box-shadow: var(--ring);
}
</style>
