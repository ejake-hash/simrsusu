<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { refDebounced } from '@/shared/composables/refDebounced'
import { daftarBarang, tambahItem, ubahItem } from '../api'
import { ringkasObat } from '../formularium'
import type { Barang, ItemFormularium, UbahItem } from '../tipe'
import { galatUsulan, useModeUsulan } from '../usulanFormularium'
import GalatForm from './GalatForm.vue'
import KolomUsulan from './KolomUsulan.vue'

/**
 * Tambah barang aktif ke formularium, atau ubah restriksi, batas per resep, dan tanda kronis satu item. Barang tidak dapat
 * diganti pada item yang ada. Status lewat DialogStatus. Sesudah siap melayani, perubahan yang sama diajukan sebagai usulan (2.12).
 */
const props = defineProps<{ formulariumId: string; item: Berversi<ItemFormularium> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: []; muatUlang: [] }>()

const KOLOM = ['barang_id', 'restriksi', 'maks_per_resep', 'kronis', 'dasar_perubahan'] as const
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const hasilCari = ref<Barang[]>([])
const dipilih = ref<Barang | null>(null)
const restriksi = ref('')
const maks = ref('')
const kronis = ref(false)
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const galat = ref<unknown>(null)
const sibuk = ref(false)
const { aktif: modeUsulan, berlaku, alasan, reset: resetUsulan, tangkap, periksa: periksaUsulan, ajukan } = useModeUsulan()

watch(buka, (b) => {
  if (!b) return
  const i = props.item?.data
  cari.value = ''
  hasilCari.value = []
  dipilih.value = null
  restriksi.value = i?.restriksi ?? ''
  maks.value = i?.maks_per_resep?.toString() ?? ''
  kronis.value = i?.kronis ?? false
  dasar.value = ''
  kesalahan.value = {}
  galat.value = null
  resetUsulan()
})
// Pemilih hanya menampilkan barang aktif (barang nonaktif ditolak server, 422).
watch(cariTunda, async (t) => {
  if (!buka.value || props.item || t.trim().length < 2) {
    hasilCari.value = []
    return
  }
  try {
    hasilCari.value = (await daftarBarang({ cari: t.trim(), status: 'aktif' }, null, 10)).data
  } catch (e) {
    galat.value = e
  }
})

async function kirim() {
  const e: Record<string, string> = {}
  const m = maks.value.trim()
  const maksAngka = m ? Number(m) : null
  if (!props.item && !dipilih.value) e.barang_id = 'Pilih barang dari hasil pencarian.'
  if (maksAngka !== null && !(Number.isInteger(maksAngka) && maksAngka >= 1)) e.maks_per_resep = 'Isi bilangan bulat minimal 1, atau kosongkan.'
  if (restriksi.value.length > 1000) e.restriksi = 'Restriksi maksimal 1000 karakter.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  if (modeUsulan.value) Object.assign(e, periksaUsulan())
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  galat.value = null
  const rs = restriksi.value.trim() || null
  try {
    if (props.item) {
      const i = props.item.data
      const ubah: UbahItem = { dasar_perubahan: dasar.value.trim() }
      if (rs !== i.restriksi) ubah.restriksi = rs
      if (maksAngka !== i.maks_per_resep) ubah.maks_per_resep = maksAngka
      if (kronis.value !== i.kronis) ubah.kronis = kronis.value
      if (Object.keys(ubah).length < 2) {
        galat.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      const { dasar_perubahan, ...nilai } = ubah
      if (modeUsulan.value) await ajukan({ objek_jenis: 'formularium_item', aksi: 'ubah', objek_id: i.id, nilai_baru: nilai }, dasar_perubahan)
      else await ubahItem(props.formulariumId, i.id, props.item.etag, ubah)
    } else {
      const isi = { barang_id: dipilih.value!.id, restriksi: rs, maks_per_resep: maksAngka, kronis: kronis.value }
      if (modeUsulan.value) await ajukan({ objek_jenis: 'formularium_item', aksi: 'tambah', objek_id: null, nilai_baru: { formularium_id: props.formulariumId, ...isi } }, dasar.value.trim())
      else await tambahItem(props.formulariumId, { ...isi, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai')
  } catch (err) {
    if (tangkap(err)) return
    const kolom = modeUsulan.value ? galatUsulan(err) : errorKolom(err, KOLOM)
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else galat.value = err
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="item ? `Ubah item ${item.data.barang.nama}` : 'Tambah barang ke formularium'" :sibuk="sibuk">
    <form id="form-item-formularium" novalidate @submit.prevent="kirim">
      <template v-if="!item">
        <KolomIsian v-slot="a" label="Cari barang aktif" :error="kesalahan.barang_id" petunjuk="Ketik minimal 2 huruf kode, nama, atau zat aktif." wajib>
          <input v-bind="a" v-model="cari" type="search" class="isian" autocomplete="off" />
        </KolomIsian>
        <p v-if="dipilih" class="terpilih" role="status">Dipilih: <b>{{ dipilih.nama }}</b> <code>{{ dipilih.kode }}</code> · {{ ringkasObat(dipilih) }}</p>
        <ul v-if="hasilCari.length" class="hasil" aria-label="Hasil pencarian barang">
          <li v-for="b in hasilCari" :key="b.id">
            <button type="button" class="tombol hasil-baris" :aria-pressed="dipilih?.id === b.id" @click="dipilih = b">
              <b>{{ b.nama }}</b> <code>{{ b.kode }}</code>
              <span>{{ ringkasObat(b) }}</span>
            </button>
          </li>
        </ul>
      </template>
      <p v-else class="terpilih">Barang: <b>{{ item.data.barang.nama }}</b> <code>{{ item.data.barang.kode }}</code></p>
      <KolomIsian v-slot="a" label="Restriksi penggunaan" :error="kesalahan.restriksi" petunjuk="Mis. batasan dari Fornas. Kosong = tanpa restriksi.">
        <textarea v-bind="a" v-model="restriksi" class="isian" rows="2" maxlength="1000" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Maksimum per resep" :error="kesalahan.maks_per_resep" petunjuk="Dalam satuan terkecil. Kosong = tidak dibatasi.">
        <input v-bind="a" v-model="maks" class="isian" inputmode="numeric" autocomplete="off" />
      </KolomIsian>
      <label class="kronis"><input v-model="kronis" type="checkbox" /> Obat kronis</label>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomUsulan v-if="modeUsulan" v-model:berlaku="berlaku" v-model:alasan="alasan" :kesalahan="kesalahan" />
      <GalatForm :galat="galat" @muat-ulang="((buka = false), emit('muatUlang'))" />
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-item-formularium" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : modeUsulan ? 'Ajukan usulan' : item ? 'Simpan' : 'Tambah ke formularium' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.hasil {
  display: grid;
  gap: var(--space-1);
  max-height: 200px;
  margin: 0 0 var(--space-3);
  padding: 0;
  overflow-y: auto;
  list-style: none;
}
.hasil-baris {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  width: 100%;
  text-align: left;
}
.hasil-baris[aria-pressed='true'] {
  border-color: var(--brand-500);
  background: var(--brand-50);
}
.terpilih {
  margin: 0 0 var(--space-3);
}
.kronis {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
</style>
