<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { errorKolom, pesanError } from '@/modules/akses/pengaturan'
import type { Berversi } from '@/modules/organisasi/api'
import { ApiError } from '@/shared/api/klien'
import { formatTanggal } from '@/shared/utils/waktu'
import { ubahPegawai } from '../api'
import { hariIniLokal } from '../sdm'
import type { PegawaiRinci } from '../tipe'

/**
 * Catat pegawai keluar (tanggal = hari pertama tidak bekerja; alasan wajib) atau batalkan rencana keluar. Mulai
 * tanggal itu akunnya nonaktif dan sesinya diputus (9.2.6); membatalkan rencana tidak mengaktifkan akun yang sudah
 * nonaktif. 409 superadmin_aktif: akhiri penugasan superadmin lewat persetujuan superadmin lain lebih dulu.
 */
const props = defineProps<{ pegawai: Berversi<PegawaiRinci> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<PegawaiRinci>]; muatUlang: [] }>()

const tanggal = ref('')
const alasan = ref('')
const dasar = ref('')
const kesalahan = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const versiBerubah = ref(false)
const sibuk = ref(false)

/** Pegawai yang sudah punya rencana keluar: dialog membatalkan rencana itu. */
const batalkan = computed(() => props.pegawai?.data.status === 'akan_keluar')
const judul = computed(() => (batalkan.value ? 'Batalkan rencana keluar' : `Catat keluar: ${props.pegawai?.data.nama ?? ''}`))
const langsung = computed(() => !!tanggal.value && tanggal.value <= hariIniLokal())

watch(buka, (b) => {
  if (!b) return
  tanggal.value = ''
  alasan.value = ''
  dasar.value = ''
  kesalahan.value = {}
  error.value = null
  versiBerubah.value = false
})

async function kirim() {
  if (!props.pegawai) return
  const e: Record<string, string> = {}
  if (!batalkan.value) {
    if (!tanggal.value) e.tanggal_keluar = 'Isi tanggal keluar.'
    if (alasan.value.trim().length < 5) e.alasan_keluar = 'Isi alasan keluar, minimal 5 karakter.'
  }
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  kesalahan.value = e
  if (Object.keys(e).length) return
  sibuk.value = true
  error.value = null
  try {
    const hasil = await ubahPegawai(
      props.pegawai.data.id,
      props.pegawai.etag,
      batalkan.value
        ? { tanggal_keluar: null, alasan_keluar: null, dasar_perubahan: dasar.value.trim() }
        : { tanggal_keluar: tanggal.value, alasan_keluar: alasan.value.trim(), dasar_perubahan: dasar.value.trim() },
    )
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    const kolom = errorKolom(err, ['tanggal_keluar', 'alasan_keluar', 'dasar_perubahan'])
    if (Object.keys(kolom).length) kesalahan.value = kolom
    else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Data pegawai ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="judul" :sibuk="sibuk" peringatan>
    <form id="form-keluar" novalidate @submit.prevent="kirim">
      <template v-if="batalkan && pegawai">
        <p class="catatan catatan-info">
          <Ikon nama="info" :ukuran="16" />
          <span>
            Rencana keluar {{ formatTanggal(`${pegawai.data.tanggal_keluar}T00:00:00`) }} dibatalkan. Akun yang sudah dinonaktifkan tidak aktif otomatis;
            aktifkan lewat Pengaturan → Akun & akses.
          </span>
        </p>
      </template>
      <template v-else>
        <p class="catatan catatan-peringatan">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>Mulai tanggal keluar, akun pegawai ini dinonaktifkan dan sesinya diputus. Kepala unit atau ketua KSM yang keluar tetap tercatat dan ditandai.</span>
        </p>
        <div class="dua-kolom">
          <KolomIsian v-slot="a" label="Tanggal keluar" :error="kesalahan.tanggal_keluar" petunjuk="Hari pertama tidak lagi bekerja." wajib>
            <input v-bind="a" v-model="tanggal" type="date" class="isian" />
          </KolomIsian>
        </div>
        <p v-if="langsung" class="ket" role="status">Tanggal hari ini atau sebelumnya: akun langsung dinonaktifkan saat disimpan.</p>
        <KolomIsian v-slot="a" label="Alasan keluar" :error="kesalahan.alasan_keluar" wajib>
          <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="500" />
        </KolomIsian>
      </template>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="kesalahan.dasar_perubahan" petunjuk="Nomor SK pemberhentian, mutasi, atau memo." wajib>
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
      <Tombol type="submit" form="form-keluar" :varian="batalkan ? 'utama' : 'bahaya'" :sibuk="sibuk">
        {{ sibuk ? 'Menyimpan…' : batalkan ? 'Batalkan rencana keluar' : 'Catat keluar' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0 var(--space-3);
}
.catatan {
  margin: 0 0 var(--space-3);
}
.ket {
  margin: calc(-1 * var(--space-2)) 0 var(--space-3);
  color: var(--muted);
  font-size: 12.5px;
}
.muat-ulang {
  margin-top: var(--space-2);
}
</style>
