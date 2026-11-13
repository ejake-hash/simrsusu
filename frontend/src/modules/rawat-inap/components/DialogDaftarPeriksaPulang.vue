<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { ubahDaftarPeriksaPulang } from '../api'
import type { DaftarPeriksaPulangDataInduk, UbahDaftarPeriksaPulang } from '../tipe'

/**
 * Ubah satu butir daftar periksa pulang (tiket 2.40; FR-05-03): nama, wajib, urutan, status. Kode dan sumber tetap. Pola
 * area IGD: dasar perubahan wajib, If-Match `versi` baris; 412 → tombol muat ulang. Berlaku untuk rencana pulang yang dibuat
 * sesudahnya.
 */
const props = defineProps<{ butir: DaftarPeriksaPulangDataInduk | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: DaftarPeriksaPulangDataInduk]; muatUlang: [] }>()

const nama = ref('')
const wajib = ref(true)
const urutan = ref<string | number>('0')
const status = ref<'aktif' | 'nonaktif'>('aktif')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

watch(buka, (b) => {
  if (!b || !props.butir) return
  nama.value = props.butir.nama
  wajib.value = props.butir.wajib
  urutan.value = String(props.butir.urutan)
  status.value = props.butir.status
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  const b = props.butir
  if (!b) return
  const e: Record<string, string> = {}
  const n = nama.value.trim()
  const uTeks = String(urutan.value ?? '').trim()
  const u = Number(uTeks)
  if (n.length < 2 || n.length > 150) e.nama = 'Isi nama butir, 2 sampai 150 karakter.'
  if (uTeks === '' || !Number.isInteger(u) || u < 0 || u > 999) e.urutan = 'Isi bilangan bulat 0 sampai 999.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  const ubah: UbahDaftarPeriksaPulang = { dasar_perubahan: dasar.value.trim() }
  if (n !== b.nama) ubah.nama = n
  if (wajib.value !== b.wajib) ubah.wajib = wajib.value
  if (u !== b.urutan) ubah.urutan = u
  if (status.value !== b.status) ubah.status = status.value
  if (Object.keys(ubah).length < 2) {
    error.value = 'Belum ada perubahan untuk disimpan.'
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahDaftarPeriksaPulang(b, ubah)
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['nama', 'wajib', 'urutan', 'status', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Butir ini diubah orang lain sejak daftar dimuat. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="butir ? `Ubah butir ${butir.kode}` : 'Ubah butir'" :sibuk="sibuk">
    <form id="form-daftar-periksa-pulang" novalidate @submit.prevent="kirim">
      <p v-if="butir" class="ket">
        Sumber: {{ butir.sumber === 'kejadian' ? 'kejadian modul lain (kasir, Tahap D), tidak dicentang petugas' : 'dicentang petugas' }}. Kode dan sumber tidak bisa diubah.
      </p>
      <KolomIsian v-slot="a" label="Nama butir" :error="kesalahan.nama" petunjuk="Nama yang tampil di daftar periksa rencana pulang." wajib>
        <input v-bind="a" v-model="nama" class="isian" autocomplete="off" maxlength="150" />
      </KolomIsian>
      <label class="centang">
        <input v-model="wajib" type="checkbox" />
        <span>Wajib selesai sebelum pulang, atau diberi alasan per butir</span>
      </label>
      <KolomIsian v-slot="a" label="Urutan tampil" :error="kesalahan.urutan" petunjuk="Angka kecil tampil lebih dulu di daftar periksa.">
        <input v-bind="a" v-model="urutan" class="isian angka" type="number" inputmode="numeric" min="0" max="999" step="1" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Status" :error="kesalahan.status" petunjuk="Butir nonaktif tidak disalin ke rencana pulang baru.">
        <select v-bind="a" v-model="status" class="isian status">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
        </select>
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
      <Tombol type="submit" form="form-daftar-periksa-pulang" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.ket {
  margin: 0 0 var(--space-3);
  font-size: 13px;
  color: var(--muted);
}
.centang {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  margin-bottom: var(--space-2);
  cursor: pointer;
}
.angka {
  max-width: 160px;
  font-variant-numeric: tabular-nums;
}
.status {
  max-width: 200px;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
