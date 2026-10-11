<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import { ubahKsm, ubahUnit, type Berversi } from '../api'
import type { Ksm, UnitOrganisasi } from '../tipe'

/**
 * Nonaktifkan atau aktifkan kembali unit/KSM (tanpa hapus, PRD 11.1). 409 *_masih_dipakai ditampilkan sebagai daftar
 * yang harus dibereskan lebih dulu (anak aktif, penugasan, modul aktif) dari `rincian` server.
 */
const props = defineProps<{ jenis: 'unit' | 'ksm'; target: Berversi<UnitOrganisasi | Ksm> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<UnitOrganisasi | Ksm>]; muatUlang: [] }>()

const dasar = ref('')
const errorDasar = ref<string | undefined>()
const error = ref<string | null>(null)
const masihDipakai = ref<string[]>([])
const versiBerubah = ref(false)
const sibuk = ref(false)

const statusBaru = computed(() => (props.target?.data.status === 'aktif' ? 'nonaktif' : 'aktif'))
const nama = computed(() => (props.target ? `${props.target.data.kode} · ${props.target.data.nama}` : ''))
const judul = computed(() => `${statusBaru.value === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan kembali'} ${props.jenis === 'unit' ? 'unit' : 'KSM'}?`)
const uraian = computed(() =>
  statusBaru.value === 'nonaktif'
    ? `${nama.value} tidak bisa dipilih untuk data baru. Data lama tetap tersimpan dan tetap menampilkan ${props.jenis === 'unit' ? 'unit' : 'KSM'} ini.`
    : `${nama.value} bisa dipilih lagi untuk data baru.`,
)

watch(buka, (b) => {
  if (!b) return
  dasar.value = ''
  errorDasar.value = undefined
  error.value = null
  masihDipakai.value = []
  versiBerubah.value = false
})

function daftarPemakaian(r: Record<string, unknown> | undefined): string[] {
  if (!r) return []
  const n = (k: string) => (typeof r[k] === 'number' ? (r[k] as number) : 0)
  return [
    n('jumlah_anak_aktif') && `${n('jumlah_anak_aktif')} unit anak masih aktif: nonaktifkan atau pindahkan lebih dulu.`,
    n('jumlah_penugasan') && `${n('jumlah_penugasan')} akun masih punya penugasan dengan cakupan ini: akhiri di Pengaturan → Akun & akses.`,
    n('jumlah_modul_aktif') && `${n('jumlah_modul_aktif')} modul masih aktif untuk unit ini.`,
  ].filter((x): x is string => !!x)
}

async function kirim() {
  const d = dasar.value.trim()
  if (d.length < 3) {
    errorDasar.value = 'Isi dasar perubahan, minimal 3 karakter.'
    return
  }
  errorDasar.value = undefined
  if (!props.target) return
  sibuk.value = true
  error.value = null
  masihDipakai.value = []
  try {
    const t = props.target
    const isian = { status: statusBaru.value, dasar_perubahan: d } as const
    const hasil = props.jenis === 'unit' ? await ubahUnit(t.data.id, t.etag, isian) : await ubahKsm(t.data.id, t.etag, isian)
    buka.value = false
    emit('selesai', hasil)
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      masihDipakai.value = daftarPemakaian(e.rincian[0] as Record<string, unknown> | undefined)
      error.value = e.message
    } else if (e instanceof ApiError && e.status === 412) {
      versiBerubah.value = true
      error.value = 'Data ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :uraian="uraian" :sibuk="sibuk" :peringatan="statusBaru === 'nonaktif'">
    <form id="form-status-org" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorDasar" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <div v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <div>
          <p class="pesan">{{ error }}</p>
          <ul v-if="masihDipakai.length" class="pemakaian">
            <li v-for="m in masihDipakai" :key="m">{{ m }}</li>
          </ul>
        </div>
      </div>
      <button v-if="versiBerubah" type="button" class="tombol muat-ulang" @click="((buka = false), emit('muatUlang'))">Muat ulang</button>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-status-org" :varian="statusBaru === 'nonaktif' ? 'bahaya' : 'utama'" :sibuk="sibuk">
        {{ sibuk ? 'Menyimpan…' : statusBaru === 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.pesan {
  margin: 0;
}
.pemakaian {
  margin: var(--space-1) 0 0;
  padding-left: 18px;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
