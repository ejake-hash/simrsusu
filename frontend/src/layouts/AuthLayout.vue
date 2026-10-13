<script setup lang="ts">
import { useProfilRs } from '@/app/profilRs'
import PanelMerek from './PanelMerek.vue'
import PitaDemo from './PitaDemo.vue'

const profilRs = useProfilRs()
</script>

<template>
  <div class="halaman" :class="{ 'dengan-pita': profilRs.demo }">
    <PitaDemo v-if="profilRs.demo" class="pita" />
    <main class="panel-form">
      <div class="atas">
        <img class="logo-produk" src="/logo/karsitara.png" alt="Karsitara" width="160" height="28" />
        <div v-if="profilRs.nama" class="kartu-rs">
          <span class="nama-rs">{{ profilRs.nama }}</span>
          <!-- Nama RS sudah tertulis di sebelahnya, jadi logo dekoratif bagi pembaca layar. -->
          <img v-if="profilRs.logo" :src="profilRs.logo" alt="" width="36" height="36" />
        </div>
      </div>

      <div class="isi">
        <div class="kolom">
          <RouterView />
        </div>
      </div>

      <p class="pemberitahuan">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M8 1.5 2.5 3.5v4c0 3.3 2.3 6 5.5 7 3.2-1 5.5-3.7 5.5-7v-4L8 1.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" />
        </svg>
        <span>Sistem ini berisi data kesehatan yang dilindungi. Setiap akses dicatat dan hanya untuk keperluan pelayanan sesuai kewenangan Anda.</span>
      </p>

      <footer class="kaki">
        <span>Dikembangkan oleh</span>
        <img src="/logo/ksn.png" alt="Karya Sistem Nusantara" width="74" height="18" />
      </footer>
    </main>

    <!-- Panel merek: dekoratif, disembunyikan dari pembaca layar dan di layar sempit. -->
    <PanelMerek class="panel" :isi="profilRs.halamanMasuk" :nama-rs="profilRs.nama" :foto="profilRs.foto" :motif="profilRs.motif" />
  </div>
</template>

<style scoped>
.halaman {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr);
  min-height: 100vh;
  background: var(--surface);
  line-height: 1.5;
}

/* ── Kolom form ── */
.panel-form {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: var(--space-8) 48px;
}
/* Pita demo = baris sendiri selebar halaman, di atas kolom form dan panel merek. */
.dengan-pita {
  grid-template-rows: auto 1fr;
}
.pita {
  grid-column: 1 / -1;
}
.atas {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--space-3) var(--space-4);
}
.logo-produk {
  display: block;
  width: auto;
  height: 28px;
}
.kartu-rs {
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: 260px;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.35;
  text-align: right;
}
.kartu-rs img {
  flex: none;
}
.isi {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 0;
}
.kolom {
  width: 100%;
  max-width: 380px;
}
.pemberitahuan {
  display: flex;
  gap: var(--space-2);
  width: 100%;
  max-width: 380px;
  margin: var(--space-6) auto 0;
  color: var(--muted);
  font-size: 12px;
}
.pemberitahuan svg {
  flex: none;
  margin-top: 2px;
}
.kaki {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 28px;
  color: var(--muted);
  font-size: 12px;
}
.kaki img {
  display: block;
  width: auto;
  height: 18px;
}

/* ── Panel merek (isi dan gaya di PanelMerek.vue) ── */
.panel {
  margin: 12px 12px 12px 0;
}

@media (max-width: 960px) {
  .halaman {
    grid-template-columns: minmax(0, 1fr);
  }
  .panel {
    display: none;
  }
  .panel-form {
    padding: var(--space-6) var(--space-4);
  }
  /* Form di atas, bukan di tengah layar: tetap terlihat saat keyboard ponsel terbuka. */
  .isi {
    align-items: flex-start;
    padding: var(--space-8) 0;
  }
}

/* Kartu RS turun ke baris sendiri: rata kiri dengan logo di depan. */
@media (max-width: 480px) {
  .kartu-rs {
    flex-direction: row-reverse;
    text-align: left;
  }
}
</style>
