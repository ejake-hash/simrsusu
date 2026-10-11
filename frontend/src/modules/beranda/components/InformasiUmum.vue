<script setup lang="ts">
import type { ProfilRs } from '@/app/profilRs'

/** Informasi umum: identitas RS dan kontak pengelola akun. Pengumuman menunggu tiket 1.7 (butuh pemilik konten). */
defineProps<{ profil: ProfilRs }>()
</script>

<template>
  <section class="kartu" aria-labelledby="judul-info-umum">
    <div class="kartu-kepala">
      <h2 id="judul-info-umum">Informasi umum</h2>
    </div>
    <div class="kartu-isi">
      <div v-if="profil.nama" class="rs">
        <!-- Nama RS tertulis di sebelahnya, jadi logo dekoratif. -->
        <img v-if="profil.logo" :src="profil.logo" alt="" width="30" height="32" />
        <b>{{ profil.nama }}</b>
      </div>
      <p class="kontak">
        Bantuan akun dan SIMRS: {{ profil.unitAdmin }}<template v-if="profil.kontakAdmin">, {{ profil.kontakAdmin }}</template>.
      </p>
    </div>
  </section>
</template>

<style scoped>
.rs {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: var(--space-2);
  font-size: 13.5px;
}
.rs img {
  flex: none;
  width: 30px;
  height: 32px;
  object-fit: contain;
}
.kontak {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
</style>
