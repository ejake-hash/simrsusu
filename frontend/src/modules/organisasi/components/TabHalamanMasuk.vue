<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { useForm } from 'vee-validate'
import { computed, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { z } from 'zod'
import Sakelar from '@/design-system/components/Sakelar.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import PanelMerek from '@/layouts/PanelMerek.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { ubahProfilRs, type Berversi } from '../api'
import { BATAS_HALAMAN_MASUK as B, bersihkanTeks, halamanMasukEfektif, panjangTeks } from '../halamanMasuk'
import { KUNCI_ORG } from '../organisasi'
import type { HalamanMasuk, IsianProfilRs, ProfilRsApi } from '../tipe'
import BerkasIdentitas from './BerkasIdentitas.vue'

/**
 * Pengaturan → Profil rumah sakit → Halaman masuk (tiket 1.15, kontrak v0.15.0). Teks panel merek dan foto diatur
 * dari layar, dengan pratinjau langsung memakai PanelMerek yang sama dengan halaman masuk. Simpan lewat PUT /profil-rs
 * (If-Match + dasar perubahan): isian identitas dikirim ulang dari profil yang dimuat, `halaman_masuk` diganti utuh,
 * atau `null` untuk kembali ke teks bawaan. Teks biasa saja; panjang dihitung sesudah dibersihkan, sama dengan server.
 */
const props = defineProps<{ profil: Berversi<ProfilRsApi>; bolehUbah: boolean }>()
const emit = defineEmits<{ disimpan: [hasil: Berversi<ProfilRsApi>]; diunggah: [hasil: Berversi<ProfilRsApi>] }>()
const qc = useQueryClient()

const wajib = (maks: number) =>
  z
    .string()
    .refine((v) => panjangTeks(v) > 0, 'Wajib diisi.')
    .refine((v) => panjangTeks(v) <= maks, `Maksimal ${maks} karakter.`)
const opsional = (maks: number) => z.string().refine((v) => panjangTeks(v) <= maks, `Maksimal ${maks} karakter.`)
const skema = z.object({
  lencana: wajib(B.lencana),
  judul: wajib(B.judul),
  judul_sorot: opsional(B.judul_sorot),
  uraian: wajib(B.uraian),
  f0_judul: wajib(B.fitur_judul),
  f0_uraian: wajib(B.fitur_uraian),
  f1_judul: wajib(B.fitur_judul),
  f1_uraian: wajib(B.fitur_uraian),
  f2_judul: wajib(B.fitur_judul),
  f2_uraian: wajib(B.fitur_uraian),
  foto_keterangan: opsional(B.foto_keterangan),
  tampilkan_motif: z.boolean(),
  dasar_perubahan: z.string().trim().min(3, 'Isi dasar perubahan, minimal 3 karakter.').max(200),
})
type Isian = z.infer<typeof skema>
const { defineField, handleSubmit, errors, resetForm, setErrors, meta, values, validateField } = useForm<Isian>({ validationSchema: skemaZod(skema) })
const [lencana] = defineField('lencana')
const [judul] = defineField('judul')
const [judulSorot] = defineField('judul_sorot')
const [uraian] = defineField('uraian')
const [foto] = defineField('foto_keterangan')
const [motif] = defineField('tampilkan_motif')
const [dasar] = defineField('dasar_perubahan')
const FITUR = [0, 1, 2] as const
const kolomFitur = FITUR.map((i) => ({ judul: defineField(`f${i}_judul`)[0], uraian: defineField(`f${i}_uraian`)[0] }))

/** Versi dasar formulir (If-Match), lihat ProfilRsPage: muat ulang di latar tidak menggeser dasar ini. */
const etagDasar = ref<string | null>(null)
function isiForm({ data: p, etag }: Berversi<ProfilRsApi>) {
  etagDasar.value = etag
  const h = halamanMasukEfektif(p)
  resetForm({
    values: {
      lencana: h.lencana,
      judul: h.judul,
      judul_sorot: h.judul_sorot ?? '',
      uraian: h.uraian,
      ...Object.fromEntries(h.fitur.flatMap((f, i) => [[`f${i}_judul`, f.judul], [`f${i}_uraian`, f.uraian]])),
      foto_keterangan: h.foto_keterangan ?? '',
      tampilkan_motif: h.tampilkan_motif,
      dasar_perubahan: '',
    },
  })
}
isiForm(props.profil)
/** Halaman lain dari layar ini (identitas, unggah berkas) menaikkan versi; dasar ikut naik bila memang versi itu. */
function ikutVersi(sebelum: string | undefined, sesudah: string) {
  if (etagDasar.value === sebelum) etagDasar.value = sesudah
}
defineExpose({ ikutVersi })

const sorot = (v: string | undefined) => bersihkanTeks(v ?? '') || null
/** Isi yang akan dikirim (dan dipratinjau): teks dibersihkan seperti di server. */
const isiBersih = computed<HalamanMasuk>(() => ({
  lencana: bersihkanTeks(values.lencana ?? ''),
  judul: bersihkanTeks(values.judul ?? ''),
  judul_sorot: sorot(values.judul_sorot),
  uraian: bersihkanTeks(values.uraian ?? ''),
  fitur: FITUR.map((i) => ({ judul: bersihkanTeks(values[`f${i}_judul`] ?? ''), uraian: bersihkanTeks(values[`f${i}_uraian`] ?? '') })),
  foto_keterangan: sorot(values.foto_keterangan),
  tampilkan_motif: values.tampilkan_motif ?? true,
}))
const hitung = (v: string | undefined, maks: number) => `${panjangTeks(v ?? '')}/${maks} karakter`

// PUT /profil-rs butuh isian identitas lengkap; instalasi yang belum punya nama harus menyimpan identitas dulu.
const identitasSiap = computed(() => !!props.profil.data.nama && !!props.profil.data.rinci)
function isianIdentitas(p: ProfilRsApi): Omit<IsianProfilRs, 'halaman_masuk' | 'dasar_perubahan'> {
  const r = p.rinci!
  return {
    nama: p.nama!,
    kode_kemenkes: r.kode_kemenkes,
    kelas: r.kelas,
    status_blu: r.status_blu,
    kode_ppk_bpjs: r.kode_ppk_bpjs,
    alamat: r.alamat,
    warna: p.paket_identitas.warna,
    kontak_admin: p.kontak_admin,
  }
}

const sibuk = ref<'simpan' | 'bawaan' | null>(null)
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const KOLOM = ['lencana', 'judul', 'judul_sorot', 'uraian', ...FITUR.flatMap((i) => [`f${i}_judul`, `f${i}_uraian`]), 'foto_keterangan', 'dasar_perubahan']
const PETA = Object.fromEntries([
  ...['lencana', 'judul', 'judul_sorot', 'uraian', 'foto_keterangan', 'tampilkan_motif'].map((k) => [`halaman_masuk.${k}`, k]),
  ...FITUR.flatMap((i) => [[`halaman_masuk.fitur.${i}.judul`, `f${i}_judul`], [`halaman_masuk.fitur.${i}.uraian`, `f${i}_uraian`]]),
])

async function simpan(halamanMasuk: HalamanMasuk | null, dasarPerubahan: string, jenis: 'simpan' | 'bawaan') {
  if (!etagDasar.value || !identitasSiap.value) return
  sibuk.value = jenis
  error.value = null
  versiBerubah.value = false
  try {
    const hasil = await ubahProfilRs(etagDasar.value, { ...isianIdentitas(props.profil.data), halaman_masuk: halamanMasuk, dasar_perubahan: dasarPerubahan.trim() })
    emit('disimpan', hasil)
    isiForm(hasil)
    tampilkanToast(jenis === 'bawaan' ? 'Halaman masuk kembali ke teks bawaan.' : 'Halaman masuk disimpan dan langsung dipakai.')
  } catch (e) {
    const kolom = errorKolom(e, KOLOM, PETA)
    if (Object.keys(kolom).length) setErrors(kolom)
    if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Profil diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru, lalu ulangi.'
    } else if (!Object.keys(kolom).length) error.value = pesanError(e)
  } finally {
    sibuk.value = null
  }
}
const kirim = handleSubmit((v) => simpan(isiBersih.value, v.dasar_perubahan, 'simpan'))
async function kembaliBawaan() {
  const { valid } = await validateField('dasar_perubahan')
  if (!valid) return
  if (!window.confirm('Ganti semua teks panel halaman masuk dengan teks bawaan? Foto tidak berubah.')) return
  await simpan(null, values.dasar_perubahan ?? '', 'bawaan')
}
async function muatUlang() {
  versiBerubah.value = false
  error.value = null
  await qc.refetchQueries({ queryKey: KUNCI_ORG.profil })
  const terbaru = qc.getQueryData<Berversi<ProfilRsApi>>(KUNCI_ORG.profil)
  if (terbaru) isiForm(terbaru)
}

onBeforeRouteLeave(() => {
  if (!meta.value.dirty || sibuk.value) return true
  return window.confirm('Ada perubahan halaman masuk yang belum disimpan. Tinggalkan halaman dan buang perubahan?')
})
</script>

<template>
  <div class="tata">
    <form class="kartu" novalidate aria-labelledby="judul-halaman-masuk" @submit.prevent="kirim">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-halaman-masuk">Panel halaman masuk</h2>
          <p>{{ halamanMasukEfektif(profil.data).bawaan ? 'Memakai teks bawaan.' : 'Teks sudah diatur rumah sakit.' }} Teks biasa saja; tag HTML tampil apa adanya.</p>
        </div>
      </div>
      <p v-if="bolehUbah && !identitasSiap" class="catatan catatan-info kartu-isi">
        <Ikon nama="info" :ukuran="16" />
        <span>Simpan identitas rumah sakit (tab Identitas) lebih dulu; halaman masuk disimpan bersama profil itu.</span>
      </p>
      <fieldset class="kartu-isi isi-form" :disabled="!bolehUbah">
        <legend class="sr-only">Teks panel halaman masuk</legend>
        <KolomIsian v-slot="a" label="Lencana" :error="errors.lencana" :petunjuk="hitung(lencana, B.lencana)" wajib>
          <input v-bind="a" v-model="lencana" class="isian" autocomplete="off" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Judul" :error="errors.judul" :petunjuk="hitung(judul, B.judul)" wajib>
          <input v-bind="a" v-model="judul" class="isian" autocomplete="off" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Bagian sorot judul (opsional)" :error="errors.judul_sorot" :petunjuk="`Ditempel di akhir judul dengan warna penekanan. ${hitung(judulSorot, B.judul_sorot)}`">
          <input v-bind="a" v-model="judulSorot" class="isian" autocomplete="off" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Uraian" :error="errors.uraian" :petunjuk="hitung(uraian, B.uraian)" wajib>
          <textarea v-bind="a" v-model="uraian" class="isian" rows="3" />
        </KolomIsian>

        <fieldset v-for="i in FITUR" :key="i" class="fitur">
          <legend>Butir fitur {{ i + 1 }}</legend>
          <KolomIsian v-slot="a" label="Judul butir" :error="errors[`f${i}_judul`]" :petunjuk="hitung(kolomFitur[i]!.judul.value, B.fitur_judul)" wajib>
            <input v-bind="a" v-model="kolomFitur[i]!.judul.value" class="isian" autocomplete="off" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Uraian butir" :error="errors[`f${i}_uraian`]" :petunjuk="hitung(kolomFitur[i]!.uraian.value, B.fitur_uraian)" wajib>
            <textarea v-bind="a" v-model="kolomFitur[i]!.uraian.value" class="isian" rows="2" />
          </KolomIsian>
        </fieldset>

        <KolomIsian v-slot="a" label="Keterangan foto (opsional)" :error="errors.foto_keterangan" :petunjuk="`Kosong = nama rumah sakit. ${hitung(foto, B.foto_keterangan)}`">
          <input v-bind="a" v-model="foto" class="isian" autocomplete="off" />
        </KolomIsian>
        <Sakelar v-model="motif" class="sakelar-motif" label="Tampilkan motif paket identitas di panel" />

        <KolomIsian v-slot="a" label="Dasar perubahan" :error="errors.dasar_perubahan" petunjuk="Nomor SK, memo direksi, atau persetujuan." wajib>
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
        </KolomIsian>
        <p v-if="error" class="catatan catatan-bahaya" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>{{ error }}</span>
        </p>
        <div v-if="bolehUbah" class="aksi">
          <button v-if="versiBerubah" type="button" class="tombol" @click="muatUlang">Muat ulang profil</button>
          <button v-if="!halamanMasukEfektif(profil.data).bawaan" type="button" class="tombol" :disabled="sibuk !== null || !identitasSiap" @click="kembaliBawaan">
            {{ sibuk === 'bawaan' ? 'Mengembalikan…' : 'Kembali ke teks bawaan' }}
          </button>
          <button type="button" class="tombol" :disabled="sibuk !== null || !meta.dirty" @click="isiForm(profil)">Batalkan perubahan</button>
          <Tombol type="submit" varian="utama" :sibuk="sibuk === 'simpan'" :disabled="!identitasSiap || sibuk === 'bawaan'">
            {{ sibuk === 'simpan' ? 'Menyimpan…' : 'Simpan halaman masuk' }}
          </Tombol>
        </div>
        <p v-else class="catatan catatan-info">
          <Ikon nama="info" :ukuran="16" />
          <span>Anda hanya bisa melihat isi halaman masuk.</span>
        </p>
      </fieldset>
    </form>

    <div class="samping">
      <section class="kartu" aria-labelledby="judul-pratinjau-masuk">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-pratinjau-masuk">Pratinjau panel</h2>
            <p>Dengan isian formulir, sebelum disimpan. Di layar di bawah 960 px panel ini tidak tampil.</p>
          </div>
        </div>
        <div class="kartu-isi wadah-pratinjau">
          <div class="pratinjau" role="img" aria-label="Pratinjau panel halaman masuk">
            <PanelMerek class="panel" :isi="isiBersih" :nama-rs="profil.data.nama" :foto="profil.data.paket_identitas.foto_url" :motif="profil.data.paket_identitas.motif_url" />
          </div>
        </div>
      </section>
      <section class="kartu" aria-labelledby="judul-foto-panel">
        <div class="kartu-kepala">
          <div>
            <h2 id="judul-foto-panel">Foto panel</h2>
            <p>Disimpan langsung saat diunggah; tidak menunggu tombol Simpan halaman masuk.</p>
          </div>
        </div>
        <div class="kartu-isi">
          <BerkasIdentitas :profil="profil" :boleh-ubah="bolehUbah" :jenis="['foto']" @diunggah="emit('diunggah', $event)" @versi-berubah="versiBerubah = true" />
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.tata {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
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
.fitur {
  margin: 0 0 var(--space-3);
  padding: var(--space-3) var(--space-3) 0;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.fitur legend {
  padding: 0 var(--space-1);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.sakelar-motif {
  margin-bottom: var(--space-4);
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin-top: var(--space-2);
}
/* Panel asli (± 720 × 880 px) diperkecil dengan zoom agar tata letak di dalamnya sama dengan halaman masuk. */
.wadah-pratinjau {
  container-type: inline-size;
}
.pratinjau {
  width: 720px;
  zoom: 0.62;
}
.panel {
  height: 880px;
}
@container (min-width: 600px) {
  .pratinjau {
    zoom: 0.8;
  }
}
@container (max-width: 446px) {
  .pratinjau {
    zoom: 0.5;
  }
}
@container (max-width: 359px) {
  .pratinjau {
    zoom: 0.42;
  }
}
@container (max-width: 301px) {
  .pratinjau {
    zoom: 0.36;
  }
}
@media (max-width: 1279.98px) {
  .tata {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
