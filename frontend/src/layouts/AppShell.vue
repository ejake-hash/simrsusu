<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cariPosisi, useMenuSaya } from '@/app/menu'
import { useProfilRs } from '@/app/profilRs'
import { useSesiStore } from '@/app/sesi'
import NotifikasiToast from '@/design-system/components/NotifikasiToast.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import LoncengNotifikasi from '@/modules/notifikasi/components/LoncengNotifikasi.vue'
import MenuAkun from './MenuAkun.vue'
import { useKelasLayar } from '@/shared/composables/useKelasLayar'
import PemilihUnitKerja from './PemilihUnitKerja.vue'
import PencarianMenu from './PencarianMenu.vue'
import PitaDemo from './PitaDemo.vue'
import { adalahPintasanCari, bukaPencarianMenu, pencarianTerbuka } from './pencarianMenu'
import SidebarMenu from './SidebarMenu.vue'

const sesi = useSesiStore()
const router = useRouter()
const route = useRoute()
const profilRs = useProfilRs()
const kelas = useKelasLayar()
const menu = useMenuSaya()

const namaTampil = computed(() => sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '')
const inisial = computed(() =>
  namaTampil.value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((kata) => kata[0]?.toUpperCase())
    .join(''),
)
const jumlahMenu = computed(() => menu.data.value?.grup.reduce((n, g) => n + g.menu.length, 0) ?? 0)

const posisi = computed(() => (menu.data.value ? cariPosisi(menu.data.value.grup, route.path) : null))
const remah = computed(() => {
  const p = posisi.value
  if (!p) return { atas: null, judul: route.meta.judul ?? '' }
  return p.sub ? { atas: p.menu.label, judul: p.sub.label } : { atas: p.grup.label, judul: p.menu.label }
})

// Laci menu untuk tablet tegak dan ponsel (PRD 5.5).
const modeLaci = computed(() => kelas.value === 'tablet-tegak' || kelas.value === 'ponsel')
const laciTerbuka = ref(false)
const tombolMenu = ref<HTMLButtonElement | null>(null)
const tombolTutup = ref<HTMLButtonElement | null>(null)

async function bukaLaci() {
  laciTerbuka.value = true
  await nextTick()
  tombolTutup.value?.focus()
}

async function tutupLaci() {
  if (!laciTerbuka.value) return
  laciTerbuka.value = false
  // Header masih inert sampai render berikutnya; fokus baru bisa kembali setelah itu.
  await nextTick()
  tombolMenu.value?.focus()
}

watch(
  () => route.fullPath,
  () => {
    laciTerbuka.value = false
  },
)
watch(modeLaci, (laci) => {
  if (!laci) laciTerbuka.value = false
})

// Ctrl/Cmd+K di layar mana pun membuka pencarian menu (PRD 5.1 butir 8; tombol header adalah jalan lain).
function tekanTombol(e: KeyboardEvent) {
  if (!adalahPintasanCari(e)) return
  e.preventDefault()
  bukaPencarianMenu()
}
// Tinggi header lekat (+ pita demo) sebagai variabel CSS untuk panel lekat dan scroll-margin di halaman.
const atasLekat = ref<HTMLElement | null>(null)
let pengamat: ResizeObserver | undefined
onMounted(() => {
  window.addEventListener('keydown', tekanTombol)
  if (typeof ResizeObserver === 'undefined' || !atasLekat.value) return
  pengamat = new ResizeObserver(([e]) => document.documentElement.style.setProperty('--tinggi-atas-lekat', `${Math.round(e!.contentRect.height)}px`))
  pengamat.observe(atasLekat.value)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', tekanTombol)
  pengamat?.disconnect()
  // Keluar/sesi berakhir: pencarian tidak terbuka lagi saat akun berikutnya masuk.
  pencarianTerbuka.value = false
})

async function keluar() {
  await sesi.keluar()
  await router.replace({ name: 'masuk' })
}
</script>

<template>
  <div class="shell">
    <a class="lewati" href="#konten">Lewati ke konten</a>

    <aside id="sidebar" class="sidebar" :class="{ terbuka: laciTerbuka }" aria-label="Navigasi utama" @keydown.esc="tutupLaci">
      <div class="sb-merek">
        <img class="logo-produk" src="/logo/karsitara.png" alt="Karsitara" width="137" height="24" />
        <button v-if="modeLaci" ref="tombolTutup" type="button" class="tombol-ikon" aria-label="Tutup menu" @click="tutupLaci">
          <Ikon nama="tutup" :ukuran="20" />
        </button>
      </div>

      <div v-if="profilRs.nama" class="kartu-rs">
        <!-- Nama RS tertulis di sebelahnya, jadi logo dekoratif bagi pembaca layar. -->
        <img v-if="profilRs.logo" :src="profilRs.logo" alt="" width="30" height="32" />
        <span class="nama-rs">{{ profilRs.nama }}</span>
      </div>

      <SidebarMenu v-if="menu.data.value" :grup="menu.data.value.grup" :kelas="kelas" @pilih="laciTerbuka = false" />
      <div v-else-if="menu.isError.value" class="error-menu" role="alert">
        <p>Menu gagal dimuat.</p>
        <button type="button" class="tombol" @click="menu.refetch()">Coba lagi</button>
      </div>
      <div v-else class="memuat" role="status" aria-label="Memuat menu">
        <span v-for="i in 6" :key="i" class="bilah" />
      </div>

      <div class="sb-kaki">
        <template v-if="modeLaci">
          <b>{{ namaTampil }}</b>
          <span>{{ sesi.pengguna?.nama_pengguna }}</span>
        </template>
        <template v-else>Menu mengikuti peran akun · {{ jumlahMenu }} menu</template>
      </div>
    </aside>
    <div v-if="laciTerbuka" class="tirai" @click="tutupLaci" />

    <div class="utama" :inert="laciTerbuka || undefined">
      <!-- Pita demo ikut lekat bersama header agar tetap terlihat saat halaman digulir (FR-54-10). -->
      <div ref="atasLekat" class="atas-lekat">
        <PitaDemo v-if="profilRs.demo" />
        <header class="kepala">
          <button
            ref="tombolMenu"
            type="button"
            class="tombol-ikon tombol-menu"
            aria-label="Buka menu"
            aria-controls="sidebar"
            :aria-expanded="laciTerbuka"
            @click="bukaLaci"
          >
            <Ikon nama="menu" :ukuran="20" />
          </button>

          <div class="remah">
            <small v-if="remah.atas">{{ remah.atas }}</small>
            <b>{{ remah.judul }}</b>
          </div>

          <!-- Pencarian pasien global dibangun di fase 2 (PRD 5.1 butir 1); sekarang penanda tempat. -->
          <div class="cari" role="search">
            <Ikon nama="cari" :ukuran="16" />
            <input
              type="search"
              disabled
              placeholder="Cari pasien: nama, No. RM, NIK, atau nomor kartu BPJS"
              aria-label="Cari pasien"
              aria-describedby="cari-segera"
            />
            <span id="cari-segera" class="segera">Segera<span class="sr-only"> hadir</span></span>
          </div>

          <PemilihUnitKerja />
          <PencarianMenu />

          <LoncengNotifikasi />
          <div class="akun">
            <span class="avatar" aria-hidden="true">{{ inisial }}</span>
            <span class="akun-teks">
              <b>{{ namaTampil }}</b>
              <small>{{ sesi.pengguna?.nama_pengguna }}</small>
            </span>
            <MenuAkun />
            <button type="button" class="tombol-ikon" aria-label="Keluar" title="Keluar" @click="keluar">
              <Ikon nama="keluar" />
            </button>
          </div>
        </header>
      </div>

      <main id="konten" class="konten" tabindex="-1">
        <RouterView />
      </main>
      <NotifikasiToast />
    </div>
  </div>
</template>

<style scoped>
.shell {
  --lebar-sidebar: 248px;
  --tinggi-kepala: 60px;
  display: grid;
  grid-template-columns: var(--lebar-sidebar) minmax(0, 1fr);
  min-height: 100vh;
}

.lewati {
  position: absolute;
  left: var(--space-2);
  top: -100px;
  z-index: 50;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-input);
  background: var(--brand-600);
  color: var(--surface);
  font-weight: var(--font-weight-semibold);
}
.lewati:focus {
  top: var(--space-2);
}

/* ── Sidebar ── */
.sidebar {
  box-sizing: border-box;
  position: sticky;
  top: 0;
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--surface);
  border-right: 1px solid var(--border);
}
.sb-merek {
  flex: none;
  height: var(--tinggi-kepala);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 18px;
  border-bottom: 1px solid var(--border);
}
.logo-produk {
  display: block;
  width: 137px;
  height: 24px;
}
.kartu-rs {
  flex: none;
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 14px 14px 6px;
  padding: 10px;
  border: 1px solid var(--border);
  border-top: 3px solid var(--accent);
  border-radius: 10px;
  background: var(--bg);
}
.kartu-rs img {
  flex: none;
  width: 30px;
  height: 32px;
  object-fit: contain;
}
.nama-rs {
  font-size: 11.5px;
  line-height: 1.35;
  color: var(--muted);
}
.sb-kaki {
  flex: none;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: var(--space-3) 18px;
  border-top: 1px solid var(--border);
  font-size: 11.5px;
  color: var(--subtle);
}
.sb-kaki b {
  font-size: 13px;
  color: var(--text);
}
.memuat {
  flex: 1;
  display: grid;
  align-content: start;
  gap: var(--space-3);
  padding: var(--space-4) 20px;
}
.bilah {
  height: 14px;
  border-radius: 4px;
  background: var(--bg);
}
.error-menu {
  flex: 1;
  padding: var(--space-4) 20px;
  color: var(--danger);
}
.error-menu p {
  margin: 0 0 var(--space-2);
}

/* ── Header ── */
.utama {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.atas-lekat {
  position: sticky;
  top: 0;
  z-index: 5;
}
.kepala {
  height: var(--tinggi-kepala);
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 0 var(--space-6);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}
.remah {
  flex: none;
  min-width: 0;
  max-width: 240px;
}
.remah small {
  display: block;
  font-size: 11.5px;
  line-height: 1.2;
  color: var(--subtle);
}
.remah b {
  display: block;
  overflow: hidden;
  font-size: 15px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cari {
  position: relative;
  flex: 1;
  min-width: 0;
  max-width: 520px;
  margin: 0 auto;
}
.cari svg {
  position: absolute;
  left: var(--space-3);
  top: 11px;
  color: var(--subtle);
}
.cari input {
  box-sizing: border-box;
  width: 100%;
  height: 38px;
  padding: 0 72px 0 38px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg);
  color: var(--text);
  font: inherit;
  text-overflow: ellipsis;
  cursor: not-allowed;
}
.cari input::placeholder {
  color: var(--subtle);
}
.cari .segera {
  position: absolute;
  right: var(--space-2);
  top: 9px;
  padding: 1px var(--space-2);
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--muted);
  font-size: 11px;
  font-weight: var(--font-weight-semibold);
}
.akun {
  flex: none;
  display: flex;
  align-items: center;
  gap: 10px;
}
.avatar {
  flex: none;
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: var(--brand-50);
  color: var(--brand-800);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
}
.akun-teks {
  line-height: 1.25;
}
.akun-teks b {
  display: block;
  font-size: 13px;
}
.akun-teks small {
  font-size: 11.5px;
  color: var(--muted);
}
.tombol-ikon {
  box-sizing: border-box;
  flex: none;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border: 1px solid var(--border-input);
  border-radius: 10px;
  background: var(--surface);
  color: var(--muted);
  cursor: pointer;
}
.tombol-ikon.tombol-menu {
  display: none;
}
.tombol-ikon:hover {
  background: var(--bg);
  color: var(--text);
}
.tombol-ikon:focus-visible,
.tombol:focus-visible {
  outline: none;
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
.tombol {
  box-sizing: border-box;
  height: 36px;
  padding: 0 var(--space-4);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: var(--font-weight-semibold);
  cursor: pointer;
}


/* ── Konten ── */
.konten {
  box-sizing: border-box;
  width: 100%;
  max-width: 1440px;
  margin: 0 auto;
  padding: var(--space-6);
}
.konten:focus {
  outline: none;
}

/* Kelas tablet 768–1279 px: target sentuh minimal 44 px (PRD 5.5 aturan 1). */
@media (min-width: 768px) and (max-width: 1279.98px) {
  .tombol-ikon {
    width: 44px;
    height: 44px;
  }
  .tombol {
    height: 44px;
  }
}

/* Tablet mendatar 1024–1279 px: sidebar ringkas 64 px. */
@media (min-width: 1024px) and (max-width: 1279.98px) {
  .shell {
    --lebar-sidebar: 64px;
  }
  .sb-merek {
    justify-content: center;
    padding: 0;
  }
  .logo-produk {
    width: 24px;
    object-fit: cover;
    object-position: left;
  }
  .kartu-rs {
    justify-content: center;
    margin: 10px 8px 6px;
    padding: 6px 0;
  }
  .nama-rs {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
  }
  .sb-kaki {
    display: none;
  }
  .memuat {
    padding: var(--space-4) 10px;
  }
}

/* Di bawah 1024 px: sidebar menjadi laci; nama akun pindah ke kaki laci. */
@media (max-width: 1023.98px) {
  .shell {
    grid-template-columns: minmax(0, 1fr);
  }
  .sidebar {
    position: fixed;
    left: 0;
    top: 0;
    z-index: 30;
    width: min(var(--lebar-sidebar), 85vw);
    transform: translateX(-100%);
    visibility: hidden;
    transition:
      transform 0.2s,
      visibility 0s 0.2s;
  }
  .sidebar.terbuka {
    transform: none;
    visibility: visible;
    box-shadow: var(--shadow-laci);
    transition: transform 0.2s;
  }
  .tirai {
    position: fixed;
    inset: 0;
    z-index: 25;
    background: var(--tirai);
  }
  .tombol-ikon.tombol-menu {
    display: grid;
  }
  .remah,
  .akun-teks {
    display: none;
  }
  .kepala {
    gap: 10px;
    padding: 0 var(--space-4);
  }
}

@media (max-width: 767.98px) {
  .konten {
    padding: var(--space-4);
  }
  .cari input {
    padding-right: 12px;
  }
  .cari .segera {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sidebar,
  .sidebar.terbuka {
    transition: none;
  }
}
</style>
