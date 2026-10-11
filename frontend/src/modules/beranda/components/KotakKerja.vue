<script setup lang="ts">
/**
 * Kotak kerja (PRD 7.53). Sampai modul notifikasi dan tugas (tiket 1.7) aktif, keadaan kosong yang jujur tanpa
 * angka. Tiket 1.7 mengisi slot bawaan dengan daftar tugas dan tab Tugas saya/Unit saya/Dieskalasi/Selesai.
 */
</script>

<template>
  <section class="kartu" aria-labelledby="judul-kotak-kerja">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-kotak-kerja">Kotak kerja</h2>
        <p>Tugas yang tertinggal dikejar sistem: pengingat, teguran, lalu eskalasi.</p>
      </div>
    </div>
    <div class="kartu-isi">
      <slot>
        <p class="kosong">Tugas dan pengingat tampil di sini setelah modul notifikasi aktif.</p>
      </slot>
    </div>
  </section>
</template>

<style scoped>
.kosong {
  margin: 0;
  padding: var(--space-6) 0;
  text-align: center;
  color: var(--muted);
}
</style>
