<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { semuaPenjaminAktif } from '@/modules/penjamin/api'
import type { Penjamin } from '@/modules/penjamin/tipe'
import { buatFormularium, ubahFormularium } from '../api'
import { JENIS_FORMULARIUM, LABEL_JENIS_FORMULARIUM } from '../formularium'
import type { Formularium, JenisFormularium, UbahFormularium } from '../tipe'
import { galatUsulan, useModeUsulan } from '../usulanFormularium'
import GalatForm from './GalatForm.vue'
import KolomUsulan from './KolomUsulan.vue'

/**
 * Tambah atau ubah formularium. Kode, jenis, dan penjamin tidak dapat diubah sesudah dibuat. Sesudah siap melayani
 * server menolak dengan 409 `harus_lewat_usulan` dan dialog mengajukan usulan (tiket 2.12): formularium baru berlaku mulai
 * tanggal usulan; perubahan hanya nama dan tanggal akhir (kontrak v0.24.0).
 */
const props = defineProps<{ formularium: Berversi<Formularium> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil?: Berversi<Formularium>]; muatUlang: [] }>()

const KOLOM = ['kode', 'nama', 'jenis', 'penjamin_id', 'berlaku_dari', 'berlaku_sampai', 'dasar_perubahan'] as const
const kode = ref('')
const nama = ref('')
const jenis = ref<JenisFormularium>('rs')
const penjaminId = ref('')
const dari = ref('')
const sampai = ref('')
const dasar = ref('')
const daftarPenjamin = ref<Penjamin[]>([])
const kesalahan = ref<Record<string, string>>({})
const galat = ref<unknown>(null)
const sibuk = ref(false)
const { aktif: modeUsulan, berlaku, alasan, reset: resetUsulan, tangkap, periksa: periksaUsulan, ajukan } = useModeUsulan()

watch(buka, (b) => {
  if (!b) return
  const f = props.formularium?.data
  kode.value = f?.kode ?? ''
  nama.value = f?.nama ?? ''
  jenis.value = f?.jenis ?? 'rs'
  penjaminId.value = f?.penjamin?.id ?? ''
  dari.value = f?.berlaku_dari ?? ''
  sampai.value = f?.berlaku_sampai ?? ''
  dasar.value = ''
  kesalahan.value = {}
  galat.value = null
  resetUsulan()
})
watch([buka, jenis], async ([b, j]) => {
  if (b && j === 'penjamin' && !props.formularium && daftarPenjamin.value.length === 0) {
    try {
      daftarPenjamin.value = await semuaPenjaminAktif()
    } catch (e) {
      galat.value = e
    }
  }
})

async function kirim() {
  const e: Record<string, string> = {}
  const k = kode.value.trim()
  if (!/^[A-Z0-9][A-Z0-9._-]{0,29}$/.test(k)) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1–30 karakter.'
  if (nama.value.trim().length < 2) e.nama = 'Isi nama formularium, minimal 2 karakter.'
  if (jenis.value === 'penjamin' && !penjaminId.value) e.penjamin_id = 'Pilih penjamin untuk formularium jenis penjamin.'
  if (!dari.value && !modeUsulan.value) e.berlaku_dari = 'Isi tanggal mulai berlaku.'
  if (sampai.value && dari.value && sampai.value < dari.value) e.berlaku_sampai = 'Tanggal akhir tidak boleh sebelum tanggal mulai.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  if (modeUsulan.value) Object.assign(e, periksaUsulan())
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  galat.value = null
  try {
    let hasil: Berversi<Formularium>
    if (props.formularium) {
      const f = props.formularium.data
      const ubah: UbahFormularium = { dasar_perubahan: dasar.value.trim() }
      if (nama.value.trim() !== f.nama) ubah.nama = nama.value.trim()
      if (dari.value !== f.berlaku_dari) ubah.berlaku_dari = dari.value
      if ((sampai.value || null) !== f.berlaku_sampai) ubah.berlaku_sampai = sampai.value || null
      if (Object.keys(ubah).length < 2) {
        galat.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      if (modeUsulan.value) {
        const { dasar_perubahan, berlaku_dari, ...nilai } = ubah
        if (berlaku_dari !== undefined || !Object.keys(nilai).length) {
          galat.value = berlaku_dari !== undefined ? 'Tanggal mulai formularium yang sudah ada tidak bisa diubah lewat usulan.' : 'Belum ada perubahan untuk diusulkan.'
          return
        }
        await ajukan({ objek_jenis: 'formularium', aksi: 'ubah', objek_id: f.id, nilai_baru: nilai }, dasar_perubahan)
        buka.value = false
        emit('selesai')
        return
      }
      hasil = await ubahFormularium(f.id, props.formularium.etag, ubah)
    } else if (modeUsulan.value) {
      await ajukan(
        { objek_jenis: 'formularium', aksi: 'tambah', objek_id: null, nilai_baru: { kode: k, nama: nama.value.trim(), jenis: jenis.value, penjamin_id: jenis.value === 'penjamin' ? penjaminId.value : null, berlaku_sampai: sampai.value || null } },
        dasar.value.trim(),
      )
      buka.value = false
      emit('selesai')
      return
    } else {
      hasil = await buatFormularium({
        kode: k,
        nama: nama.value.trim(),
        jenis: jenis.value,
        ...(jenis.value === 'penjamin' ? { penjamin_id: penjaminId.value } : {}),
        berlaku_dari: dari.value,
        berlaku_sampai: sampai.value || null,
        dasar_perubahan: dasar.value.trim(),
      })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    if (tangkap(err)) {
      if (!props.formularium && dari.value) berlaku.value = dari.value
      // Tanggal mulai formularium yang ada tidak bisa diusulkan berubah (kontrak: ubah hanya nama dan tanggal akhir).
      if (props.formularium) dari.value = props.formularium.data.berlaku_dari
      return
    }
    if (modeUsulan.value) {
      const [kolom, sisa] = galatUsulan(err, KOLOM)
      kesalahan.value = kolom
      galat.value = sisa ?? (Object.keys(kolom).length ? null : err)
      return
    }
    const kolom = errorKolom(err, KOLOM)
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else galat.value = err
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="formularium ? `Ubah formularium ${formularium.data.kode}` : 'Tambah formularium'" :sibuk="sibuk">
    <form id="form-formularium" novalidate @submit.prevent="kirim">
      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Kode formularium" :error="kesalahan.kode" wajib>
          <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="30" :disabled="!!formularium" @input="kode = kode.toUpperCase()" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Jenis formularium" :error="kesalahan.jenis" wajib>
          <select v-bind="a" v-model="jenis" class="isian" :disabled="!!formularium">
            <option v-for="j in JENIS_FORMULARIUM" :key="j" :value="j">{{ LABEL_JENIS_FORMULARIUM[j] }}</option>
          </select>
        </KolomIsian>
      </div>
      <KolomIsian v-if="jenis === 'penjamin'" v-slot="a" label="Penjamin" :error="kesalahan.penjamin_id" wajib>
        <select v-bind="a" v-model="penjaminId" class="isian" :disabled="!!formularium">
          <option v-if="formularium?.data.penjamin" :value="formularium.data.penjamin.id">{{ formularium.data.penjamin.nama }}</option>
          <template v-else>
            <option value="">Pilih penjamin</option>
            <option v-for="p in daftarPenjamin" :key="p.id" :value="p.id">{{ p.nama }}</option>
          </template>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Nama formularium" :error="kesalahan.nama" wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
      </KolomIsian>
      <div class="dua-kolom">
        <KolomIsian v-if="!modeUsulan || formularium" v-slot="a" label="Berlaku dari" :error="modeUsulan ? undefined : kesalahan.berlaku_dari" wajib>
          <input v-bind="a" v-model="dari" type="date" class="isian" :disabled="modeUsulan" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Berlaku sampai" :error="kesalahan.berlaku_sampai" petunjuk="Kosong = tanpa batas akhir.">
          <input v-bind="a" v-model="sampai" type="date" class="isian" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomUsulan v-if="modeUsulan" v-model:berlaku="berlaku" v-model:alasan="alasan" :kesalahan="kesalahan" />
      <GalatForm :galat="galat" @muat-ulang="((buka = false), emit('muatUlang'))" />
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-formularium" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : modeUsulan ? 'Ajukan usulan' : formularium ? 'Simpan' : 'Tambah formularium' }}</Tombol>
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
</style>
