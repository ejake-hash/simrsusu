<script setup lang="ts">
import { computed } from 'vue'
import { useMenuSaya } from '@/app/menu'
import { profilRsDariEnv } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import { modulSaya, salam } from '../beranda'
import InformasiUmum from '../components/InformasiUmum.vue'
import KartuModul from '../components/KartuModul.vue'
import KeamananAkun from '../components/KeamananAkun.vue'
import KotakKerja from '../components/KotakKerja.vue'
import Pintasan from '../components/Pintasan.vue'

/**
 * Beranda (tiket 1.2e, acuan home.html): salam, modul milik akun dari /saya/menu (bukan semua modul peragaan),
 * kotak kerja jujur tanpa angka, keamanan akun, pintasan, dan informasi umum. Ponsel: kotak kerja dan keamanan
 * akun saja; modul dibuka dari tablet/komputer (PRD 5.5).
 */
const sesi = useSesiStore()
const menu = useMenuSaya()
const kelas = useKelasLayar()
const profil = profilRsDariEnv()

const sekarang = new Date()
const ucapan = salam(sekarang.getHours())
const tanggal = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(sekarang)

const nama = computed(() => sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '')
const peran = computed(() => sesi.pengguna?.peran.map((p) => p.nama).join(', ') ?? '')
const modul = computed(() => (menu.data.value ? modulSaya(menu.data.value.grup) : []))
const ponsel = computed(() => kelas.value === 'ponsel')
</script>

<template>
  <div class="salam">
    <h1>{{ ucapan }}, {{ nama }}</h1>
    <p>
      {{ tanggal }}<template v-if="peran"> · {{ peran }}</template>
    </p>
  </div>

  <section v-if="!ponsel" class="kartu modul" aria-labelledby="judul-modul">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-modul">Modul saya</h2>
        <p>Sesuai peran Anda. Modul yang belum dibangun bertanda Segera hadir.</p>
      </div>
    </div>
    <div class="kartu-isi">
      <p v-if="menu.isPending.value" class="keterangan" role="status">Memuat modul…</p>
      <p v-else-if="modul.length === 0" class="keterangan">
        Belum ada modul yang diberikan, hubungi {{ profil.unitAdmin }}<template v-if="profil.kontakAdmin"> ({{ profil.kontakAdmin }})</template>.
      </p>
      <div v-else class="kisi-modul">
        <KartuModul v-for="m in modul" :key="m.kode" :menu="m" />
      </div>
    </div>
  </section>

  <div class="tata">
    <div class="kolom-utama">
      <KotakKerja />
    </div>
    <div class="kolom-samping">
      <KeamananAkun v-if="sesi.pengguna" :pengguna="sesi.pengguna" :unit-admin="profil.unitAdmin" :kontak-admin="profil.kontakAdmin" />
      <Pintasan v-if="!ponsel" :modul="modul" />
      <InformasiUmum :profil="profil" />
    </div>
  </div>
  <p v-if="ponsel" class="keterangan ponsel">Modul lain dibuka dari tablet atau komputer.</p>
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
.modul {
  margin-bottom: var(--space-4);
}
.kisi-modul {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-3);
}
.keterangan {
  margin: 0;
  color: var(--muted);
}
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(300px, 400px);
  align-items: start;
  gap: var(--space-4);
}
.kolom-samping {
  display: grid;
  gap: var(--space-4);
}
.ponsel {
  margin-top: var(--space-4);
  font-size: 12.5px;
}
@media (max-width: 1023.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
