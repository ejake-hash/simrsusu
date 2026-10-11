<script setup lang="ts">
import { computed } from 'vue'
import { useSesiStore } from '@/app/sesi'

const sesi = useSesiStore()
const nama = computed(() => sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '')
const hariIni = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())
</script>

<template>
  <div class="salam">
    <h1>Selamat datang, {{ nama }}</h1>
    <p>{{ hariIni }}</p>
  </div>

  <!-- Kotak kerja dan ringkasan per peran dibangun di tiket 1.7 (PRD 7.53). -->
  <section class="kartu" aria-labelledby="judul-kotak-kerja">
    <h2 id="judul-kotak-kerja">Kotak kerja</h2>
    <p>Tugas dan ringkasan sesuai peran Anda akan tampil di sini.</p>
  </section>
</template>

<style scoped>
.salam {
  margin-bottom: 20px;
}
h1 {
  margin: 0 0 var(--space-1);
  font-size: 24px;
  line-height: 1.2;
  letter-spacing: -0.02em;
}
.salam p {
  margin: 0;
  color: var(--muted);
}
.kartu {
  padding: 14px 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius-card);
  background: var(--surface);
}
h2 {
  margin: 0 0 var(--space-1);
  font-size: 15px;
}
.kartu p {
  margin: 0;
  color: var(--muted);
}
</style>
