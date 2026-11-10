<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { buatAreaIgd, etagArea, ubahAreaIgd } from '../api'
import type { AreaIgdDataInduk, UbahAreaIgd } from '../tipe'

/**
 * Tambah atau ubah area IGD (tiket 2.25; FR-03-03). Nama area ditetapkan RS; urutan = urut tampil di papan dan di pilihan
 * penempatan (kecil dulu). Status lewat DialogStatusAreaIgd.
 */
const props = defineProps<{ area: AreaIgdDataInduk | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<AreaIgdDataInduk>]; muatUlang: [] }>()

const KODE = /^[A-Z0-9][A-Z0-9._-]{0,29}$/
const kode = ref('')
const nama = ref('')
const urutan = ref<string | number>('0')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b) return
  kode.value = props.area?.kode ?? ''
  nama.value = props.area?.nama ?? ''
  urutan.value = String(props.area?.urutan ?? 0)
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const e: Record<string, string> = {}
  const k = kode.value.trim()
  const n = nama.value.trim()
  // v-model input number memberi angka, atau '' saat kosong.
  const uTeks = String(urutan.value ?? '').trim()
  const u = Number(uTeks)
  if (!KODE.test(k)) e.kode = 'Huruf besar, angka, titik, garis bawah, atau tanda hubung; 1 sampai 30 karakter.'
  if (n.length < 2 || n.length > 100) e.nama = 'Isi nama area, 2 sampai 100 karakter.'
  if (uTeks === '' || !Number.isInteger(u) || u < 0 || u > 999) e.urutan = 'Isi bilangan bulat 0 sampai 999.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    let hasil: Berversi<AreaIgdDataInduk>
    if (props.area) {
      const a = props.area
      const ubah: UbahAreaIgd = { dasar_perubahan: dasar.value.trim() }
      if (k !== a.kode) ubah.kode = k
      if (n !== a.nama) ubah.nama = n
      if (u !== a.urutan) ubah.urutan = u
      if (Object.keys(ubah).length < 2) {
        error.value = 'Belum ada perubahan untuk disimpan.'
        return
      }
      hasil = await ubahAreaIgd(a.id, etagArea(a), ubah)
    } else {
      hasil = await buatAreaIgd({ kode: k, nama: n, urutan: u, dasar_perubahan: dasar.value.trim() })
    }
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['kode', 'nama', 'urutan', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Area ini diubah orang lain sejak daftar dimuat. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="area ? `Ubah area ${area.kode}` : 'Tambah area IGD'" :sibuk="sibuk">
    <form id="form-area-igd" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Kode" :error="kesalahan.kode" wajib>
        <input v-bind="a" v-model="kode" class="isian kode" autocomplete="off" spellcheck="false" maxlength="30" @input="kode = kode.toUpperCase()" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Nama area" :error="kesalahan.nama" petunjuk="Nama yang tampil di papan, mis. nama ruang atau zona IGD." wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="100" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Urutan tampil" :error="kesalahan.urutan" petunjuk="Angka kecil tampil lebih dulu di filter dan pilihan area papan.">
        <input v-bind="a" v-model="urutan" class="isian angka" type="number" inputmode="numeric" min="0" max="999" step="1" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK, notulen komite, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" maxlength="200" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-area-igd" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : area ? 'Simpan' : 'Tambah area' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.kode {
  font-family: var(--font-mono);
}
.angka {
  max-width: 160px;
  font-variant-numeric: tabular-nums;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
