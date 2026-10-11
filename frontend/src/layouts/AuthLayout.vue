<script setup lang="ts">
import { profilRsDariEnv } from '@/app/profilRs'

const profilRs = profilRsDariEnv()
</script>

<template>
  <div class="halaman">
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
    <aside class="panel-merek" aria-hidden="true" :style="profilRs.motif ? { '--motif': `url(${profilRs.motif})` } : undefined">
      <div>
        <span class="tag"><span class="titik"></span>Satu data untuk seluruh rumah sakit</span>
        <p class="judul-merek">Pelayanan, pendapatan, dan mutu dalam <em>satu layar kerja.</em></p>
        <p class="lead-merek">
          Dari pendaftaran sampai klaim, setiap pencatatan langsung menjadi rekam medis, tagihan, dan laporan, tanpa entri ulang.
        </p>
      </div>
      <figure v-if="profilRs.foto" class="foto">
        <img :src="profilRs.foto" alt="" width="1600" height="1000" decoding="async" />
        <figcaption v-if="profilRs.nama"><span class="titik"></span>{{ profilRs.nama }}</figcaption>
      </figure>
      <ul class="poin">
        <li><b>Paperless</b>Pengesahan elektronik dengan PIN, catatan terkunci, perubahan jadi adendum.</li>
        <li><b>Terhubung</b>BPJS dan SATUSEHAT berjalan dari layar yang sama.</li>
        <li><b>Pendidikan</b>Akun peserta didik mengikuti masa rotasi dan supervisi.</li>
      </ul>
    </aside>
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

/* ── Panel merek ── */
.panel-merek {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: var(--space-8);
  margin: 12px 12px 12px 0;
  padding: 48px;
  border-radius: 20px;
  background: linear-gradient(160deg, var(--usu-gelap) 0%, var(--brand-800) 55%, var(--brand-900) 100%);
  color: var(--surface);
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
  background: radial-gradient(circle, color-mix(in srgb, var(--brand-500) 45%, transparent), transparent 65%);
}
.panel-merek > * {
  position: relative;
  z-index: 1;
}
.tag {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: 5px 12px;
  border: 1px solid color-mix(in srgb, var(--surface) 16%, transparent);
  border-radius: 999px;
  color: var(--brand-100);
  font-size: 12px;
  font-weight: var(--font-weight-medium);
}
.titik {
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
}
.judul-merek em {
  font-style: normal;
  color: var(--usu-kuning);
}
.lead-merek {
  max-width: 440px;
  margin: 0;
  color: var(--brand-100);
}
.foto {
  flex: 1 1 auto;
  min-height: 200px;
  max-height: 480px;
  margin: 0;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--surface) 14%, transparent);
  border-radius: 16px;
  background: var(--brand-900);
  box-shadow: 0 24px 48px -24px var(--brand-900);
}
.foto img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: 50% 40%;
}
/* Gelap di bawah agar keterangan terbaca dan foto menyatu dengan panel hijau. */
.foto::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, color-mix(in srgb, var(--brand-900) 85%, transparent), transparent 45%);
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
.foto .titik {
  flex: none;
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
}
.poin b {
  display: block;
  margin-bottom: var(--space-1);
  color: var(--surface);
  font-size: 13px;
}

@media (max-width: 960px) {
  .halaman {
    grid-template-columns: minmax(0, 1fr);
  }
  .panel-merek {
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
