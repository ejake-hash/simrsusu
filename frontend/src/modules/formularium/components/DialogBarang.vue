<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { buatBarang, ubahBarang } from '../api'
import { JENIS_BARANG, LABEL_GENERIK_PATEN, LABEL_JENIS_BARANG } from '../formularium'
import type { Barang, GenerikPaten, JenisBarang, UbahBarang } from '../tipe'
import GalatForm from './GalatForm.vue'

/**
 * Tambah atau ubah barang. Generik dan paten dari zat yang sama = dua barang dengan dua kode (FR-22-06). `kode_kfa`
 * diisi manual sampai pencarian KFA (2.22). Status lewat DialogStatus.
 */
const props = defineProps<{ barang: Berversi<Barang> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Barang>]; muatUlang: [] }>()

const KOLOM = ['kode', 'nama', 'jenis', 'generik_paten', 'zat_aktif', 'kekuatan', 'sediaan', 'satuan_terkecil', 'kode_kfa', 'dasar_perubahan'] as const
const kode = ref('')
const nama = ref('')
const jenis = ref<JenisBarang>('obat')
const generikPaten = ref<GenerikPaten | ''>('')
const zatAktif = ref('')
const kekuatan = ref('')
const sediaan = ref('')
const satuan = ref('')
const kfa = ref('')
const waspada = ref(false)
const narkotika = ref(false)
const lasa = ref(false)
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const galat = ref<unknown>(null)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  const x = props.barang?.data
  kode.value = x?.kode ?? ''
  nama.value = x?.nama ?? ''
  jenis.value = x?.jenis ?? 'obat'
  generikPaten.value = x?.generik_paten ?? ''
  zatAktif.value = x?.zat_aktif ?? ''
  kekuatan.value = x?.kekuatan ?? ''
  sediaan.value = x?.sediaan ?? ''
  satuan.value = x?.satuan_terkecil ?? ''
  kfa.value = x?.kode_kfa ?? ''
  waspada.value = x?.kewaspadaan_tinggi ?? false
  narkotika.value = x?.narkotika ?? false
  lasa.value = x?.lasa ?? false
  dasar.value = ''
  kesalahan.value = {}
  galat.value = null
})

const atauNull = (t: string) => t.trim() || null

async function kirim() {
  const e: Record<string, string> = {}
  const k = kode.value.trim()
  if (!/^[A-Z0-9][A-Z0-9._-]{0,29}$/.test(k)) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (nama.value.trim().length < 2) e.nama = 'Isi nama barang, minimal 2 karakter.'
  if (!satuan.value.trim()) e.satuan_terkecil = 'Isi satuan terkecil, mis. tablet.'
  if (jenis.value === 'obat' && !generikPaten.value) e.generik_paten = 'Obat wajib ditandai generik atau paten.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  const isian = {
    kode: k,
    nama: nama.value.trim(),
    jenis: jenis.value,
    generik_paten: generikPaten.value || null,
    zat_aktif: atauNull(zatAktif.value),
    kekuatan: atauNull(kekuatan.value),
    sediaan: atauNull(sediaan.value),
    satuan_terkecil: satuan.value.trim(),
    kode_kfa: atauNull(kfa.value),
    kewaspadaan_tinggi: waspada.value,
    narkotika: narkotika.value,
    lasa: lasa.value,
  }
  sibuk.value = true
  galat.value = null
  try {
    let hasil: Berversi<Barang>
    if (props.barang) {
      const lama = props.barang.data
      const ubah: UbahBarang = { dasar_perubahan: dasar.value.trim() }
      for (const [kunci, nilai] of Object.entries(isian)) if (nilai !== lama[kunci as keyof Barang]) (ubah as Record<string, unknown>)[kunci] = nilai
      if (Object.keys(ubah).length < 2) {
        galat.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahBarang(lama.id, props.barang.etag, ubah)
    } else {
      hasil = await buatBarang({ ...isian, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, KOLOM)
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else galat.value = err
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="barang ? `Ubah barang ${barang.data.kode}` : 'Tambah barang'" lebar="lebar" :sibuk="sibuk">
    <form id="form-barang" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode barang" :error="kesalahan.kode" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="30" @input="kode = kode.toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Jenis" :error="kesalahan.jenis" wajib petunjuk="Tidak dapat diubah bila barang sudah ada di formularium.">
          <select v-bind="a" v-model="jenis" class="isian">
            <option v-for="j in JENIS_BARANG" :key="j" :value="j">{{ LABEL_JENIS_BARANG[j] }}</option>
          </select>
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Nama barang" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="200" />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Generik atau paten" :error="kesalahan.generik_paten" :wajib="jenis === 'obat'">
          <select v-bind="a" v-model="generikPaten" class="isian">
            <option value="">Tidak berlaku</option>
            <option v-for="(label, nilai) in LABEL_GENERIK_PATEN" :key="nilai" :value="nilai">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Satuan terkecil" :error="kesalahan.satuan_terkecil" wajib>
          <input v-bind="a" v-model="satuan" class="isian" autocomplete="off" maxlength="30" placeholder="tablet" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Zat aktif" :error="kesalahan.zat_aktif" petunjuk="Beberapa zat dipisah dengan tanda +.">
        <input v-bind="a" v-model="zatAktif" class="isian" autocomplete="off" maxlength="300" />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kekuatan" :error="kesalahan.kekuatan">
          <input v-bind="a" v-model="kekuatan" class="isian" autocomplete="off" maxlength="100" placeholder="500 mg" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Sediaan" :error="kesalahan.sediaan">
          <input v-bind="a" v-model="sediaan" class="isian" autocomplete="off" maxlength="100" placeholder="tablet" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Kode KFA" :error="kesalahan.kode_kfa" petunjuk="Boleh kosong; diisi manual sampai pencarian KFA tersedia.">
        <input v-bind="a" v-model="kfa" class="isian kode" autocomplete="off" maxlength="20" />
      </KolomIsian>
      <fieldset class="tanda">
        <legend>Tanda kewaspadaan</legend>
        <label><input v-model="waspada" type="checkbox" /> Kewaspadaan tinggi</label>
        <label><input v-model="narkotika" type="checkbox" /> Narkotika atau psikotropika</label>
        <label><input v-model="lasa" type="checkbox" /> LASA (nama mirip)</label>
      </fieldset>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <GalatForm :galat="galat" @muat-ulang="((buka = false), emit('muatUlang'))" />
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-barang" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : barang ? 'Simpan' : 'Tambah barang' }}</Tombol>
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
.tanda {
  display: grid;
  gap: var(--space-1);
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
.tanda legend {
  padding: 0;
  margin-bottom: var(--space-1);
  font-weight: 600;
}
.tanda label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
</style>
