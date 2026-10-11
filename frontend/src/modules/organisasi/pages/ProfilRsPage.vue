<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { z } from 'zod'
import { terapkanProfilRs } from '@/app/profilRs'
import { hexKeRgb, kontras, type Rgb } from '@/app/tema'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilProfilRs, ubahProfilRs, type Berversi } from '../api'
import BerkasIdentitas from '../components/BerkasIdentitas.vue'
import PratinjauIdentitas from '../components/PratinjauIdentitas.vue'
import { KUNCI_ORG } from '../organisasi'
import type { ProfilRsApi } from '../tipe'

/**
 * Pengaturan → Profil rumah sakit (tiket 1.8a, FR-54-08 langkah 2; PRD 3.4). Identitas, kontak pengelola akun, dua
 * warna dasar paket identitas (kontras diuji langsung dengan rumus server: brand vs putih, aksen vs teks ≥ 4,5:1),
 * berkas logo/motif/foto, dan pratinjau halaman masuk + sidebar sebelum disimpan. Sesudah simpan, seluruh layar
 * memakai identitas baru tanpa muat ulang. Tanpa izin perbaiki: hanya baca.
 */
const qc = useQueryClient()
const bolehUbah = computed(() => boleh('pengaturan.profil_rs.perbaiki'))

const kueri = useQuery({ queryKey: KUNCI_ORG.profil, queryFn: ambilProfilRs, staleTime: 0 })
const profil = computed(() => kueri.data.value ?? null)

const HEX = /^#[0-9A-Fa-f]{6}$/
const opsional = (maks: number) => z.string().trim().max(maks, `Maksimal ${maks} karakter.`)
const skema = z.object({
  nama: z.string().trim().min(3, 'Nama minimal 3 karakter.').max(200),
  kode_kemenkes: opsional(20),
  kelas: opsional(20),
  status_blu: z.enum(['', 'ya', 'tidak']),
  kode_ppk_bpjs: opsional(20),
  alamat: opsional(500),
  brand: z.string().regex(HEX, 'Format warna #RRGGBB.'),
  accent: z.string().regex(HEX, 'Format warna #RRGGBB.'),
  unit_admin: z.string().trim().min(2, 'Isi unit pengelola akun, minimal 2 karakter.').max(100),
  kontak_admin: opsional(100),
  dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan, minimal 3 karakter.').max(200),
})
type Isian = z.infer<typeof skema>
const { defineField, handleSubmit, errors, resetForm, setErrors, meta, values } = useForm<Isian>({ validationSchema: skemaZod(skema) })
const [nama] = defineField('nama')
const [kodeKemenkes] = defineField('kode_kemenkes')
const [kelas] = defineField('kelas')
const [statusBlu] = defineField('status_blu')
const [kodePpk] = defineField('kode_ppk_bpjs')
const [alamat] = defineField('alamat')
const [brand] = defineField('brand')
const [accent] = defineField('accent')
const [unitAdmin] = defineField('unit_admin')
const [kontakAdmin] = defineField('kontak_admin')
const [dasar] = defineField('dasar_perubahan')

/**
 * Versi yang menjadi dasar formulir: If-Match simpan memakai ini, bukan ETag kueri terbaru. Muat ulang di latar
 * (fokus jendela) bisa membawa versi orang lain; formulir tidak diisi ulang, jadi simpan harus kalah dengan 412.
 */
const etagDasar = ref<string | null>(null)

function isiForm({ data: p, etag }: Berversi<ProfilRsApi>) {
  etagDasar.value = etag
  const r = p.rinci
  resetForm({
    values: {
      nama: p.nama ?? '',
      kode_kemenkes: r?.kode_kemenkes ?? '',
      kelas: r?.kelas ?? '',
      status_blu: r?.status_blu === true ? 'ya' : r?.status_blu === false ? 'tidak' : '',
      kode_ppk_bpjs: r?.kode_ppk_bpjs ?? '',
      alamat: r?.alamat ?? '',
      brand: p.paket_identitas.warna.brand.toUpperCase(),
      accent: p.paket_identitas.warna.accent.toUpperCase(),
      unit_admin: p.kontak_admin.unit,
      kontak_admin: p.kontak_admin.kontak ?? '',
      dasar_perubahan: '',
    },
  })
}
// Isi formulir sekali saat data pertama datang dan sesudah simpan; muat ulang latar tidak menimpa suntingan.
let terisi = false
watch(
  profil,
  (p) => {
    if (p && !terisi) {
      terisi = true
      isiForm(p)
    }
  },
  { immediate: true },
)

// ── Kontras: rumus dan pembanding sama dengan server (App\Core\ProfilRs\KontrasWarna): warna utama vs putih,
// aksen vs tinta teks rgb(29, 29, 27); keduanya ≥ 4,5:1. Server tetap penentu (422 kontras_kurang). ──
const PUTIH: Rgb = [255, 255, 255]
const TINTA: Rgb = [29, 29, 27]
function rasio(hex: string | undefined, lawan: Rgb): number | null {
  const a = hexKeRgb(hex ?? '')
  return a ? kontras(a, lawan) : null
}
const rasioBrand = computed(() => rasio(values.brand, PUTIH))
const rasioAccent = computed(() => rasio(values.accent, TINTA))
const angka = (r: number) => r.toFixed(2).replace('.', ',')

// ── Simpan ──
const sibuk = ref(false)
const error = ref<string | null>(null)
const versiBerubah = ref(false)

function pasang(hasil: Berversi<ProfilRsApi>) {
  qc.setQueryData(KUNCI_ORG.profil, hasil)
  terapkanProfilRs(hasil.data)
}
/** Unggahan sendiri menaikkan versi; dasar formulir ikut naik hanya bila memang versi sebelum unggah. */
function diunggah(hasil: Berversi<ProfilRsApi>) {
  const sebelum = profil.value?.etag
  pasang(hasil)
  if (etagDasar.value === sebelum) etagDasar.value = hasil.etag
}
async function muatUlang() {
  versiBerubah.value = false
  error.value = null
  const r = await kueri.refetch()
  if (r.data) isiForm(r.data)
}

const kirim = handleSubmit(async (v) => {
  if (!profil.value || !etagDasar.value) return
  if ((rasioBrand.value ?? 0) < 4.5 || (rasioAccent.value ?? 0) < 4.5) {
    error.value = 'Kontras warna belum cukup. Pilih warna yang lolos uji di bawah kolom warna.'
    return
  }
  sibuk.value = true
  error.value = null
  versiBerubah.value = false
  const kosongNull = (s: string | undefined) => (s?.trim() ? s.trim() : null)
  try {
    const hasil = await ubahProfilRs(etagDasar.value, {
      nama: v.nama.trim(),
      kode_kemenkes: kosongNull(v.kode_kemenkes),
      kelas: kosongNull(v.kelas),
      status_blu: v.status_blu === '' ? null : v.status_blu === 'ya',
      kode_ppk_bpjs: kosongNull(v.kode_ppk_bpjs),
      alamat: kosongNull(v.alamat),
      warna: { brand: v.brand.toUpperCase(), accent: v.accent.toUpperCase() },
      kontak_admin: { unit: v.unit_admin.trim(), kontak: kosongNull(v.kontak_admin) },
      dasar_perubahan: v.dasar_perubahan.trim(),
    })
    pasang(hasil)
    isiForm(hasil)
    tampilkanToast('Profil rumah sakit disimpan. Identitas baru langsung dipakai di semua layar.')
  } catch (e) {
    const kolom = errorKolom(e, ['nama', 'kode_kemenkes', 'kelas', 'status_blu', 'kode_ppk_bpjs', 'alamat', 'brand', 'accent', 'unit_admin', 'kontak_admin', 'dasar_perubahan'], {
      'warna.brand': 'brand',
      'warna.accent': 'accent',
      'kontak_admin.unit': 'unit_admin',
      'kontak_admin.kontak': 'kontak_admin',
    })
    if (Object.keys(kolom).length) setErrors(kolom)
    if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Profil diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru, lalu ulangi.'
    } else if (!Object.keys(kolom).length) error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})

onBeforeRouteLeave(() => {
  if (!meta.value.dirty || sibuk.value) return true
  return window.confirm('Ada perubahan profil yang belum disimpan. Tinggalkan halaman dan buang perubahan?')
})
</script>

<template>
  <HalamanModul
    izin="pengaturan.profil_rs.lihat"
    judul="Profil rumah sakit"
    uraian="Identitas RS, kontak pengelola akun, dan paket identitas (warna, logo, motif, foto) yang tampil di halaman masuk dan seluruh layar. Setiap perubahan butuh dasar dan tercatat di jejak audit."
  >
    <div v-if="kueri.isPending.value" class="kartu kartu-isi" role="status">Memuat profil rumah sakit…</div>
    <div v-else-if="!profil" class="kartu kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <p v-if="profil.data.rinci?.status_penyiapan === 'belum'" class="catatan catatan-info penyiapan">
        <Ikon nama="info" :ukuran="16" />
        <span>Instalasi ini belum disiapkan. Isi nama rumah sakit dan paket identitas sebelum dipakai; sampai saat itu halaman masuk memakai identitas Karsitara.</span>
      </p>
      <div class="tata">
        <form class="kartu" novalidate aria-labelledby="judul-identitas" @submit.prevent="kirim">
          <div class="kartu-kepala">
            <div>
              <h2 id="judul-identitas">Identitas</h2>
              <p v-if="profil.data.rinci?.diubah_pada">Terakhir diubah {{ formatTanggalWaktu(profil.data.rinci.diubah_pada) }}</p>
            </div>
          </div>
          <fieldset class="kartu-isi isi-form" :disabled="!bolehUbah">
            <legend class="sr-only">Identitas rumah sakit</legend>
            <KolomIsian v-slot="a" label="Nama rumah sakit" :error="errors.nama" wajib>
              <input v-bind="a" v-model="nama" class="isian" autocomplete="organization" />
            </KolomIsian>
            <div class="tiga-kolom">
              <KolomIsian v-slot="a" label="Kode RS Kemenkes" :error="errors.kode_kemenkes">
                <input v-bind="a" v-model="kodeKemenkes" class="isian kode" autocomplete="off" spellcheck="false" />
              </KolomIsian>
              <KolomIsian v-slot="a" label="Kelas" :error="errors.kelas" petunjuk="Sesuai izin operasional.">
                <input v-bind="a" v-model="kelas" class="isian" autocomplete="off" />
              </KolomIsian>
              <KolomIsian v-slot="a" label="Status BLU" :error="errors.status_blu">
                <select v-bind="a" v-model="statusBlu" class="isian">
                  <option value="">Belum diisi</option>
                  <option value="ya">BLU</option>
                  <option value="tidak">Bukan BLU</option>
                </select>
              </KolomIsian>
            </div>
            <KolomIsian v-slot="a" label="Kode PPK BPJS" :error="errors.kode_ppk_bpjs">
              <input v-bind="a" v-model="kodePpk" class="isian kode" autocomplete="off" spellcheck="false" />
            </KolomIsian>
            <KolomIsian v-slot="a" label="Alamat" :error="errors.alamat">
              <textarea v-bind="a" v-model="alamat" class="isian" rows="2" autocomplete="street-address" />
            </KolomIsian>

            <h3>Pengelola akun</h3>
            <div class="dua-kolom">
              <KolomIsian v-slot="a" label="Unit pengelola akun" :error="errors.unit_admin" petunjuk="Tampil di halaman masuk: “Hubungi …”." wajib>
                <input v-bind="a" v-model="unitAdmin" class="isian" autocomplete="off" />
              </KolomIsian>
              <KolomIsian v-slot="a" label="Kontak (opsional)" :error="errors.kontak_admin" petunjuk="Nomor ekstensi atau saluran bantuan.">
                <input v-bind="a" v-model="kontakAdmin" class="isian" autocomplete="off" />
              </KolomIsian>
            </div>

            <h3>Warna paket identitas</h3>
            <div class="dua-kolom">
              <KolomIsian v-slot="a" label="Warna utama" :error="errors.brand" wajib>
                <div class="warna">
                  <input v-model="brand" type="color" class="contoh" aria-label="Pilih warna utama" tabindex="-1" @input="brand = String(brand).toUpperCase()" />
                  <input v-bind="a" v-model="brand" class="isian kode" autocomplete="off" spellcheck="false" maxlength="7" />
                </div>
                <p v-if="rasioBrand !== null" class="uji" :class="rasioBrand >= 4.5 ? 'lolos' : 'gagal'" aria-live="polite">
                  <Ikon :nama="rasioBrand >= 4.5 ? 'centang' : 'peringatan'" :ukuran="14" />
                  Kontras teks putih di atas warna ini {{ angka(rasioBrand) }}:1 · {{ rasioBrand >= 4.5 ? 'lolos' : 'minimal 4,5:1' }}
                </p>
              </KolomIsian>
              <KolomIsian v-slot="a" label="Warna aksen" :error="errors.accent" wajib>
                <div class="warna">
                  <input v-model="accent" type="color" class="contoh" aria-label="Pilih warna aksen" tabindex="-1" @input="accent = String(accent).toUpperCase()" />
                  <input v-bind="a" v-model="accent" class="isian kode" autocomplete="off" spellcheck="false" maxlength="7" />
                </div>
                <p v-if="rasioAccent !== null" class="uji" :class="rasioAccent >= 4.5 ? 'lolos' : 'gagal'" aria-live="polite">
                  <Ikon :nama="rasioAccent >= 4.5 ? 'centang' : 'peringatan'" :ukuran="14" />
                  Kontras teks gelap di atas warna ini {{ angka(rasioAccent) }}:1 · {{ rasioAccent >= 4.5 ? 'lolos' : 'minimal 4,5:1' }}
                </p>
              </KolomIsian>
            </div>

            <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar_perubahan" petunjuk="Nomor SK, memo direksi, atau persetujuan." wajib>
              <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
            </KolomIsian>
            <p v-if="error" class="catatan catatan-bahaya" role="alert">
              <Ikon nama="peringatan" :ukuran="16" />
              <span>{{ error }}</span>
            </p>
            <div v-if="bolehUbah" class="aksi">
              <button v-if="versiBerubah" type="button" class="tombol" @click="muatUlang">Muat ulang profil</button>
              <button type="button" class="tombol" :disabled="sibuk || !meta.dirty" @click="isiForm(profil)">Batalkan perubahan</button>
              <Tombol type="submit" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan profil' }}</Tombol>
            </div>
            <p v-else class="catatan catatan-info">
              <Ikon nama="info" :ukuran="16" />
              <span>Anda hanya bisa melihat profil ini.</span>
            </p>
          </fieldset>
        </form>

        <div class="samping">
          <section class="kartu" aria-labelledby="judul-pratinjau">
            <div class="kartu-kepala">
              <div>
                <h2 id="judul-pratinjau">Pratinjau</h2>
                <p>Halaman masuk dan sidebar dengan isian formulir, sebelum disimpan.</p>
              </div>
            </div>
            <div class="kartu-isi">
              <PratinjauIdentitas
                :nama="values.nama ?? ''"
                :logo="profil.data.paket_identitas.logo_url"
                :brand="HEX.test(values.brand ?? '') ? values.brand! : profil.data.paket_identitas.warna.brand"
                :accent="HEX.test(values.accent ?? '') ? values.accent! : profil.data.paket_identitas.warna.accent"
                :unit-admin="values.unit_admin || 'admin SIMRS'"
              />
            </div>
          </section>
          <section class="kartu" aria-labelledby="judul-berkas">
            <div class="kartu-kepala">
              <div>
                <h2 id="judul-berkas">Berkas identitas</h2>
                <p>Disimpan langsung saat diunggah; tidak menunggu tombol Simpan profil.</p>
              </div>
            </div>
            <div class="kartu-isi">
              <BerkasIdentitas :profil="profil" :boleh-ubah="bolehUbah" @diunggah="diunggah" @versi-berubah="versiBerubah = true" />
            </div>
          </section>
        </div>
      </div>
    </template>
  </HalamanModul>
</template>

<style scoped>
.penyiapan {
  margin: 0 0 var(--space-4);
}
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
  align-items: start;
  gap: var(--space-4);
}
.samping {
  display: grid;
  gap: var(--space-4);
}
.isi-form {
  margin: 0;
  border: 0;
}
h3 {
  margin: var(--space-2) 0 var(--space-3);
  font-size: 13.5px;
}
.dua-kolom,
.tiga-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.tiga-kolom {
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}
.kode {
  font-family: var(--font-mono);
}
.warna {
  display: flex;
  gap: var(--space-2);
}
.warna .isian {
  flex: 1;
  min-width: 0;
}
.contoh {
  flex: none;
  align-self: stretch;
  width: 44px;
  height: auto;
  padding: 2px;
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  cursor: pointer;
}
.uji {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 0;
  font-size: 12px;
}
.uji.lolos {
  color: var(--success);
}
.uji.gagal {
  color: var(--danger);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin-top: var(--space-2);
}
@media (max-width: 1279.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
