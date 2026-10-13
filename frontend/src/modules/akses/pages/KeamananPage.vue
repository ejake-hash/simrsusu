<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilKebijakanKeamanan, ubahKebijakanKeamanan, type KebijakanBerversi } from '../apiPerangkat'
import { KUNCI_PERANGKAT } from '../perangkat'
import { errorKolom, pesanError } from '../pengaturan'

/**
 * Pengaturan → Keamanan (tiket 1.13, kontrak v0.16.0): masa percaya perangkat bawaan instalasi. MFA superadmin, peran
 * sensitif, dan akses dari luar jaringan RS tetap wajib; yang diatur hanya seberapa sering kode diminta di perangkat yang
 * sama. Batas atas dari konfigurasi server (tidak bisa dinaikkan dari layar). If-Match + dasar perubahan; izin perbaiki
 * sensitif (MFA ulang ditangani dialog global).
 */
const qc = useQueryClient()
const bolehUbah = computed(() => boleh('pengaturan.keamanan.perbaiki'))
const kueri = useQuery({ queryKey: KUNCI_PERANGKAT.kebijakan, queryFn: ambilKebijakanKeamanan, staleTime: 0 })
const data = computed(() => kueri.data.value ?? null)

const hari = ref('')
const dasar = ref('')
const etagDasar = ref<string | null>(null)
const kesalahan = ref<{ hari?: string; dasar?: string }>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

function isi({ kebijakan, etag }: KebijakanBerversi) {
  etagDasar.value = etag
  hari.value = String(kebijakan.perangkat_tepercaya_hari)
  dasar.value = ''
  kesalahan.value = {}
}
let terisi = false
watch(
  data,
  (d) => {
    if (d && !terisi) {
      terisi = true
      isi(d)
    }
  },
  { immediate: true },
)
const berubah = computed(() => !!data.value && hari.value !== String(data.value.kebijakan.perangkat_tepercaya_hari))

async function simpan() {
  if (!data.value || !etagDasar.value) return
  const maks = data.value.kebijakan.batas.maks_hari
  const n = Number(hari.value)
  const k: typeof kesalahan.value = {}
  if (!/^\d+$/.test(hari.value.trim()) || n > maks) k.hari = `Isi 0 sampai ${maks} hari.`
  if (dasar.value.trim().length < 3) k.dasar = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = k
  if (Object.keys(k).length) return
  sibuk.value = true
  error.value = null
  versiBerubah.value = false
  try {
    const hasil = await ubahKebijakanKeamanan(etagDasar.value, { perangkat_tepercaya_hari: n, dasar_perubahan: dasar.value.trim() })
    qc.setQueryData(KUNCI_PERANGKAT.kebijakan, hasil)
    isi(hasil)
    tampilkanToast(n === 0 ? 'Perangkat tepercaya dimatikan. Kode MFA diminta setiap masuk.' : `Masa percaya perangkat ${n} hari disimpan.`)
  } catch (e) {
    const kolom = errorKolom(e, ['hari', 'dasar'], { perangkat_tepercaya_hari: 'hari', dasar_perubahan: 'dasar' })
    if (Object.keys(kolom).length) kesalahan.value = kolom
    if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Kebijakan diubah orang lain sejak Anda membukanya. Muat ulang, lalu ulangi.'
    } else if (e instanceof ApiError && e.kode === 'masa_percaya_melebihi_batas') kesalahan.value = { hari: e.message }
    else if (!Object.keys(kolom).length) error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
async function muatUlang() {
  versiBerubah.value = false
  error.value = null
  const r = await kueri.refetch()
  if (r.data) isi(r.data)
}

onBeforeRouteLeave(() => !berubah.value || sibuk.value || window.confirm('Ada perubahan kebijakan yang belum disimpan. Tinggalkan halaman?'))
</script>

<template>
  <HalamanModul
    izin="pengaturan.keamanan.lihat"
    judul="Keamanan"
    uraian="Perangkat tepercaya: seberapa lama kode MFA tidak diminta lagi di peramban yang sama, dari jaringan RS. MFA superadmin, peran sensitif, dan akses dari luar jaringan RS tetap wajib."
  >
    <div v-if="kueri.isPending.value" class="kartu kartu-isi" role="status">Memuat kebijakan…</div>
    <div v-else-if="!data" class="kartu kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <div v-else class="tata">
      <form class="kartu" novalidate aria-labelledby="judul-perangkat-tepercaya" @submit.prevent="simpan">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-perangkat-tepercaya">Perangkat tepercaya</h2>
            <p v-if="data.kebijakan.diubah_pada">Terakhir diubah {{ formatTanggalWaktu(data.kebijakan.diubah_pada) }}</p>
          </div>
          <span class="pil" :class="data.kebijakan.perangkat_tepercaya_hari > 0 ? 'pil-ok' : 'pil-netral'">
            {{ data.kebijakan.perangkat_tepercaya_hari > 0 ? `Aktif · ${data.kebijakan.perangkat_tepercaya_hari} hari` : 'Mati' }}
          </span>
        </div>
        <fieldset class="kartu-isi isi-form" :disabled="!bolehUbah">
          <legend class="sr-only">Masa percaya perangkat</legend>
          <KolomIsian
            v-slot="a"
            label="Masa percaya bawaan (hari)"
            :error="kesalahan.hari"
            :petunjuk="`0 = fitur mati (kode diminta setiap masuk). Maksimum ${data.kebijakan.batas.maks_hari} hari.`"
            wajib
          >
            <input v-bind="a" v-model="hari" class="isian angka" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar" petunjuk="Nomor SK, memo direksi, atau keputusan komite." wajib>
            <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
          </KolomIsian>
          <p v-if="error" class="catatan catatan-bahaya" role="alert">
            <Ikon nama="peringatan" :ukuran="16" />
            <span>{{ error }}</span>
          </p>
          <div v-if="bolehUbah" class="aksi">
            <button v-if="versiBerubah" type="button" class="tombol" @click="muatUlang">Muat ulang kebijakan</button>
            <button type="button" class="tombol" :disabled="sibuk || !berubah" @click="isi(data)">Batalkan perubahan</button>
            <Tombol type="submit" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan kebijakan' }}</Tombol>
          </div>
          <p v-else class="catatan catatan-info">
            <Ikon nama="info" :ukuran="16" />
            <span>Anda hanya bisa melihat kebijakan ini.</span>
          </p>
        </fieldset>
      </form>

      <section class="kartu" aria-labelledby="judul-batas">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-batas">Batas dari konfigurasi server</h2>
            <p>Tidak bisa dinaikkan dari layar. Masa percaya akun = nilai terkecil dari kebijakan ini, peran yang dipegang, dan batas di bawah.</p>
          </div>
        </div>
        <dl class="kartu-isi batas">
          <div>
            <dt>Umum</dt>
            <dd>{{ data.kebijakan.batas.maks_hari }} hari</dd>
          </div>
          <div>
            <dt>Superadmin</dt>
            <dd>{{ data.kebijakan.batas.maks_hari_superadmin }} hari</dd>
          </div>
          <div>
            <dt>Peran dengan izin sensitif</dt>
            <dd>{{ data.kebijakan.batas.maks_hari_peran_sensitif }} hari</dd>
          </div>
        </dl>
        <p class="kartu-isi catatan-akhir">
          Ganti sandi, reset sandi, reset MFA, dan nonaktif akun mencabut semua perangkat tepercaya akun itu. Verifikasi ulang
          untuk aksi sensitif tetap meminta kode walau perangkat dipercaya.
        </p>
      </section>
    </div>
  </HalamanModul>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
}
.isi-form {
  margin: 0;
  border: 0;
}
.angka {
  max-width: 160px;
  font-variant-numeric: tabular-nums;
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin-top: var(--space-2);
}
.batas {
  display: grid;
  gap: var(--space-2);
  margin: 0;
}
.batas div {
  display: flex;
  justify-content: space-between;
  gap: var(--space-3);
}
.batas dt {
  color: var(--muted);
}
.batas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
  font-variant-numeric: tabular-nums;
}
.catatan-akhir {
  margin: 0;
  padding-top: 0;
  color: var(--muted);
  font-size: 12.5px;
}
@media (max-width: 1023.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
