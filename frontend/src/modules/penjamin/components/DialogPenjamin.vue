<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { buatPenjamin, ubahPenjamin } from '../api'
import { JENIS_PENJAMIN, LABEL_JENIS_PENJAMIN } from '../penjamin'
import type { JenisPenjamin, Penjamin, UbahPenjamin } from '../tipe'

/**
 * Tambah atau ubah penjamin. Atribut penagihan (masa berlaku GL, plafon, jatuh tempo) opsional; kosong = tidak
 * dibatasi. `jenis` ditolak server bila penjamin sudah dipakai kunjungan. Status lewat DialogStatusPenjamin.
 */
const props = defineProps<{ penjamin: Berversi<Penjamin> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Penjamin>]; muatUlang: [] }>()

const KOLOM = ['kode', 'nama', 'jenis', 'masa_berlaku_gl_hari', 'plafon', 'jatuh_tempo_hari', 'dasar_perubahan'] as const
const kode = ref('')
const nama = ref('')
const jenis = ref<JenisPenjamin>('asuransi')
const masaGl = ref('')
const plafon = ref('')
const jatuhTempo = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const p = props.penjamin?.data
  kode.value = p?.kode ?? ''
  nama.value = p?.nama ?? ''
  jenis.value = p?.jenis ?? 'asuransi'
  masaGl.value = p?.masa_berlaku_gl_hari?.toString() ?? ''
  plafon.value = p?.plafon ?? ''
  jatuhTempo.value = p?.jatuh_tempo_hari?.toString() ?? ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

/** '' → null; selain itu bilangan bulat dalam rentang, atau NaN bila tidak sah. */
function hari(teks: string, min: number, maks: number): number | null {
  const t = teks.trim()
  if (!t) return null
  const n = Number(t)
  return Number.isInteger(n) && n >= min && n <= maks ? n : Number.NaN
}

async function kirim() {
  const e: Record<string, string> = {}
  const k = kode.value.trim()
  const gl = hari(masaGl.value, 1, 3650)
  const jt = hari(jatuhTempo.value, 0, 365)
  const pf = plafon.value.trim() || null
  if (!/^[A-Z0-9][A-Z0-9._-]{0,29}$/.test(k)) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (nama.value.trim().length < 2) e.nama = 'Isi nama penjamin, minimal 2 karakter.'
  if (Number.isNaN(gl)) e.masa_berlaku_gl_hari = 'Isi bilangan bulat 1–3650, atau kosongkan.'
  if (Number.isNaN(jt)) e.jatuh_tempo_hari = 'Isi bilangan bulat 0–365, atau kosongkan.'
  if (pf !== null && !/^[0-9]{1,15}(\.[0-9]{1,2})?$/.test(pf)) e.plafon = 'Angka rupiah tanpa titik ribuan, maksimal dua desimal (contoh 25000000 atau 1500.50).'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<Penjamin>
    if (props.penjamin) {
      const p = props.penjamin.data
      const ubah: UbahPenjamin = { dasar_perubahan: dasar.value.trim() }
      if (k !== p.kode) ubah.kode = k
      if (nama.value.trim() !== p.nama) ubah.nama = nama.value.trim()
      if (jenis.value !== p.jenis) ubah.jenis = jenis.value
      if (gl !== p.masa_berlaku_gl_hari) ubah.masa_berlaku_gl_hari = gl
      if (pf !== p.plafon) ubah.plafon = pf
      if (jt !== p.jatuh_tempo_hari) ubah.jatuh_tempo_hari = jt
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahPenjamin(p.id, props.penjamin.etag, ubah)
    } else {
      hasil = await buatPenjamin({ kode: k, nama: nama.value.trim(), jenis: jenis.value, masa_berlaku_gl_hari: gl, plafon: pf, jatuh_tempo_hari: jt, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, KOLOM)
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Penjamin ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="penjamin ? `Ubah penjamin ${penjamin.data.kode}` : 'Tambah penjamin'" :sibuk="sibuk">
    <form id="form-penjamin" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode" :error="kesalahan.kode" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="30" @input="kode = kode.toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Jenis" :error="kesalahan.jenis" wajib>
          <select v-bind="a" v-model="jenis" class="isian">
            <option v-for="j in JENIS_PENJAMIN" :key="j" :value="j">{{ LABEL_JENIS_PENJAMIN[j] }}</option>
          </select>
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Nama penjamin" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
      </KolomIsian>
      <fieldset class="penagihan">
        <legend>Atribut penagihan (opsional)</legend>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Masa berlaku surat jaminan (hari)" :error="kesalahan.masa_berlaku_gl_hari" petunjuk="Sejak terbit. Kosong = tidak dibatasi.">
            <input v-bind="a" v-model="masaGl" class="isian" inputmode="numeric" autocomplete="off" />
          </KolomIsian>
          <KolomIsian v-slot="a" label="Jatuh tempo penagihan (hari)" :error="kesalahan.jatuh_tempo_hari" petunjuk="Sejak tagihan terbit. Kosong = tidak ditetapkan.">
            <input v-bind="a" v-model="jatuhTempo" class="isian" inputmode="numeric" autocomplete="off" />
          </KolomIsian>
        </div>
        <KolomIsian v-slot="a" label="Plafon (Rp)" :error="kesalahan.plafon" petunjuk="Tanpa titik ribuan. Kosong = tanpa plafon tetap.">
          <input v-bind="a" v-model="plafon" class="isian kode" inputmode="decimal" autocomplete="off" />
        </KolomIsian>
      </fieldset>
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
      <Tombol type="submit" form="form-penjamin" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : penjamin ? 'Simpan' : 'Tambah penjamin' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.penagihan {
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
.penagihan legend {
  padding: 0;
  margin-bottom: var(--space-2);
  font-weight: 600;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
