<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import type { Berversi } from '../../api'
import { buatPoliklinik, ubahPoliklinik } from '../../apiLokasi'
import type { Poliklinik } from '../../tipeLokasi'
import PemilihLokasi from '../PemilihLokasi.vue'
import PemilihUnit from '../PemilihUnit.vue'

/**
 * Tambah atau ubah poliklinik: kode, nama, unit pengelola, ruang (penunjuk arah, opsional), dan kode poli BPJS (diisi RS
 * dari referensi resmi; tidak ada daftar bawaan). Status lewat DialogStatus.
 */
const props = defineProps<{ poli: Berversi<Poliklinik> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Poliklinik>]; muatUlang: [] }>()

const kode = ref('')
const nama = ref('')
const unitId = ref<string | null>(null)
const labelUnit = ref<string | null>(null)
const lokasiId = ref<string | null>(null)
const labelLokasi = ref<string | null>(null)
const kodeBpjs = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const p = props.poli?.data
  kode.value = p?.kode ?? ''
  nama.value = p?.nama ?? ''
  unitId.value = p?.unit.id ?? null
  labelUnit.value = p ? `${p.unit.kode} · ${p.unit.nama}` : null
  lokasiId.value = p?.lokasi?.id ?? null
  labelLokasi.value = p?.lokasi ? `${p.lokasi.kode_lengkap} · ${p.lokasi.nama}` : null
  kodeBpjs.value = p?.kode_poli_bpjs ?? ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const e: Record<string, string> = {}
  if (!/^[A-Z0-9][A-Z0-9._-]{0,29}$/.test(kode.value.trim())) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (nama.value.trim().length < 2) e.nama = 'Nama minimal 2 karakter.'
  if (!unitId.value) e.unit_organisasi_id = 'Pilih unit pengelola.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  const bpjs = kodeBpjs.value.trim() || null
  try {
    let hasil: Berversi<Poliklinik>
    if (props.poli) {
      const p = props.poli.data
      const ubah: Parameters<typeof ubahPoliklinik>[2] = { dasar_perubahan: dasar.value.trim() }
      if (kode.value.trim() !== p.kode) ubah.kode = kode.value.trim()
      if (nama.value.trim() !== p.nama) ubah.nama = nama.value.trim()
      if (unitId.value !== p.unit.id) ubah.unit_organisasi_id = unitId.value!
      if (lokasiId.value !== (p.lokasi?.id ?? null)) ubah.lokasi_id = lokasiId.value
      if (bpjs !== p.kode_poli_bpjs) ubah.kode_poli_bpjs = bpjs
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahPoliklinik(p.id, props.poli.etag, ubah)
    } else {
      hasil = await buatPoliklinik({ kode: kode.value.trim(), nama: nama.value.trim(), unit_organisasi_id: unitId.value!, lokasi_id: lokasiId.value, kode_poli_bpjs: bpjs, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['kode', 'nama', 'unit_organisasi_id', 'lokasi_id', 'kode_poli_bpjs', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Poliklinik ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="poli ? `Ubah poliklinik ${poli.data.kode}` : 'Tambah poliklinik'" :sibuk="sibuk">
    <form id="form-poliklinik" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode" :error="kesalahan.kode" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" @input="kode = kode.toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kode poli BPJS (opsional)" :error="kesalahan.kode_poli_bpjs" petunjuk="Dari referensi BPJS resmi.">
          <input v-bind="a" v-model="kodeBpjs" class="isian kode" autocomplete="off" spellcheck="false" maxlength="10" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Nama poliklinik" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Unit pengelola" :error="kesalahan.unit_organisasi_id" wajib>
        <PemilihUnit v-bind="a" v-model="unitId" :label-terpilih="labelUnit" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Ruang (opsional)" :error="kesalahan.lokasi_id" petunjuk="Penunjuk arah pasien; ruang yang aktif.">
        <PemilihLokasi v-bind="a" v-model="lokasiId" :jenis="['ruang']" :label-terpilih="labelLokasi" opsi-kosong="Belum dipetakan" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-poliklinik" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : poli ? 'Simpan' : 'Tambah poliklinik' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
