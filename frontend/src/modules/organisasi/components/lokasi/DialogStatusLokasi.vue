<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ApiError } from '@/shared/api/klien'
import type { Berversi } from '../../api'
import { ubahLokasi } from '../../apiLokasi'
import { hariIniLokal, LABEL_STATUS_LOKASI } from '../../lokasi'
import type { Lokasi, StatusLokasi } from '../../tipeLokasi'

/**
 * Ubah status lokasi (aktif ↔ renovasi ↔ ditutup) mulai tanggal ≤ hari ini (penjadwalan ke depan lewat usulan 1.8c).
 * Semua perpindahan boleh; server menolak 409 lokasi_masih_dipakai (TT belum ditutup, poli aktif, turunan belum
 * ditutup) dengan rincian jumlah, dan 422 induk_tidak_aktif bila membuka kembali di bawah induk yang ditutup.
 */
const props = defineProps<{ lokasi: Berversi<Lokasi> | null }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: Berversi<Lokasi>]; muatUlang: [] }>()

const status = ref<StatusLokasi>('renovasi')
const tanggal = ref('')
const dasar = ref('')
const errorKolom = ref<{ status?: string; tanggal?: string; dasar?: string }>({})
const error = ref<string | null>(null)
const masihDipakai = ref<string[]>([])
const versiBerubah = ref(false)
const sibuk = ref(false)

const pilihan = computed(() => (['aktif', 'renovasi', 'ditutup'] as StatusLokasi[]).filter((s) => s !== props.lokasi?.data.status))
watch(buka, (b) => {
  if (!b) return
  status.value = pilihan.value.includes('renovasi') ? 'renovasi' : pilihan.value[0]!
  tanggal.value = hariIniLokal()
  dasar.value = ''
  errorKolom.value = {}
  error.value = null
  masihDipakai.value = []
  versiBerubah.value = false
})

const akibat = computed(() =>
  status.value === 'ditutup'
    ? 'Lokasi ditutup tidak bisa dipilih untuk data baru dan anak baru tidak bisa ditambahkan di bawahnya. Syarat: tidak ada TT yang belum ditutup, poliklinik aktif, atau turunan yang belum ditutup.'
    : status.value === 'renovasi'
      ? 'Renovasi: syaratnya tidak ada TT yang belum ditutup dan tidak ada poliklinik aktif di lokasi ini atau turunannya.'
      : 'Lokasi aktif kembali. Induk lokasi ini tidak boleh berstatus ditutup.',
)

function daftarPemakaian(r: Record<string, unknown> | undefined): string[] {
  if (!r) return []
  const n = (k: string) => (typeof r[k] === 'number' ? (r[k] as number) : 0)
  return [
    n('jumlah_anak_aktif') && `${n('jumlah_anak_aktif')} lokasi turunan belum ditutup.`,
    n('jumlah_tempat_tidur') && `${n('jumlah_tempat_tidur')} tempat tidur belum ditutup.`,
    n('jumlah_poliklinik') && `${n('jumlah_poliklinik')} poliklinik aktif di lokasi ini: pindahkan atau nonaktifkan di tab Poliklinik.`,
  ].filter((x): x is string => !!x)
}

async function kirim() {
  const e: typeof errorKolom.value = {}
  if (!tanggal.value) e.tanggal = 'Isi tanggal berlaku.'
  else if (tanggal.value > hariIniLokal()) e.tanggal = 'Tidak boleh di masa depan; penjadwalan lewat usulan perubahan.'
  if (dasar.value.trim().length < 3) e.dasar = 'Isi dasar perubahan, minimal 3 karakter.'
  errorKolom.value = e
  if (Object.keys(e).length || !props.lokasi) return
  sibuk.value = true
  error.value = null
  masihDipakai.value = []
  try {
    const l = props.lokasi
    const hasil = await ubahLokasi(l.data.id, l.etag, { status: status.value, status_berlaku_dari: tanggal.value, dasar_perubahan: dasar.value.trim() })
    buka.value = false
    emit('selesai', hasil)
  } catch (err) {
    if (err instanceof ApiError && err.status === 409) {
      masihDipakai.value = daftarPemakaian(err.rincian[0] as Record<string, unknown> | undefined)
      error.value = err.message
    } else if (err instanceof ApiError && err.status === 412) {
      versiBerubah.value = true
      error.value = 'Lokasi ini diubah orang lain sejak dibuka. Muat ulang, lalu ulangi.'
    } else if (err instanceof ApiError && err.kode === 'tanggal_tidak_valid') {
      errorKolom.value = { tanggal: err.rincian[0]?.pesan ?? err.message }
    } else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Ubah status ${lokasi?.data.kode_lengkap ?? ''}`" :sibuk="sibuk" :peringatan="status === 'ditutup'">
    <form id="form-status-lokasi" novalidate @submit.prevent="kirim">
      <fieldset class="status">
        <legend>Status baru</legend>
        <label v-for="s in pilihan" :key="s" class="pilihan"><input v-model="status" type="radio" name="status-lokasi" :value="s" /> {{ LABEL_STATUS_LOKASI[s] }}</label>
      </fieldset>
      <p class="catatan catatan-netral akibat">
        <Ikon nama="info" :ukuran="16" />
        <span>{{ akibat }}</span>
      </p>
      <KolomIsian v-slot="a" label="Berlaku mulai" :error="errorKolom.tanggal" petunjuk="Hari ini atau sebelumnya." wajib>
        <input v-bind="a" v-model="tanggal" type="date" class="isian" :max="hariIniLokal()" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorKolom.dasar" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
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
      <Tombol type="submit" form="form-status-lokasi" :varian="status === 'ditutup' ? 'bahaya' : 'utama'" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan status' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.status {
  margin: 0 0 var(--space-3);
  padding: 0;
  border: 0;
}
legend {
  margin-bottom: 6px;
  padding: 0;
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.pilihan {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  margin-right: var(--space-3);
}
.akibat {
  margin: 0 0 var(--space-4);
}
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
@media (min-width: 768px) and (max-width: 1279.98px) {
  .pilihan {
    min-height: 44px;
  }
}
</style>
