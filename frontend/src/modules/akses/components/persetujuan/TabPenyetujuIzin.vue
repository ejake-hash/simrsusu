<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { aturPenyetujuIzin } from '../../apiPersetujuan'
import { indeksPohon } from '../../matriks'
import { errorKolom, KUNCI, pesanError, usePeranAktif, usePohonMenu } from '../../pengaturan'
import { teksMenunggu } from '../../persetujuan'

/**
 * Peran penyetuju (pemilik data) tiap izin sensitif (K3). Izin tanpa penyetuju tidak bisa diberikan (422). Mengganti
 * penyetuju tidak langsung berlaku: 202 = menunggu persetujuan superadmin lain (kontrol ganda K3/K13), lalu tampil di
 * "Yang saya ajukan".
 */
const qc = useQueryClient()
const pohon = usePohonMenu()
const peranAktif = usePeranAktif()
const bolehUbah = computed(() => boleh('pengaturan.peran.perbaiki'))

interface BarisIzin {
  kode: string
  label: string
  penyetuju: { id: string; nama: string } | null
}
const baris = computed<BarisIzin[]>(() => {
  if (!pohon.data.value) return []
  const indeks = indeksPohon(pohon.data.value)
  return [...indeks.perKode.entries()].filter(([, v]) => v.izin.sensitif).map(([kode, v]) => ({ kode, label: v.label, penyetuju: v.izin.penyetuju_peran ?? null }))
})
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'izin', label: 'Izin' },
  { kunci: 'penyetuju', label: 'Penyetuju', lebar: '240px' },
  ...(bolehUbah.value ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '140px' }] : []),
])

const diganti = ref<BarisIzin | null>(null)
const buka = ref(false)
const peranId = ref('')
const dasar = ref('')
const errorKolomIsian = ref<{ peran_id?: string; dasar_perubahan?: string }>({})
const error = ref<string | null>(null)
const sibuk = ref(false)
watch(buka, (b) => {
  if (!b) return
  peranId.value = ''
  dasar.value = ''
  errorKolomIsian.value = {}
  error.value = null
})
function ganti(b: BarisIzin) {
  diganti.value = b
  buka.value = true
}
async function kirim() {
  const e: { peran_id?: string; dasar_perubahan?: string } = {}
  if (!peranId.value) e.peran_id = 'Pilih peran penyetuju.'
  if (dasar.value.trim().length < 3) e.dasar_perubahan = 'Isi dasar perubahan, minimal 3 karakter.'
  errorKolomIsian.value = e
  if (Object.keys(e).length || !diganti.value) return
  sibuk.value = true
  error.value = null
  try {
    const hasil = await aturPenyetujuIzin(diganti.value.kode, { peran_id: peranId.value, dasar_perubahan: dasar.value.trim() })
    buka.value = false
    tampilkanToast(hasil.menunggu ? `Penyetuju ${diganti.value.label} ${teksMenunggu(hasil.persetujuan)}.` : `Penyetuju ${diganti.value.label} diganti.`)
    void qc.invalidateQueries({ queryKey: KUNCI.pohon })
    void qc.invalidateQueries({ queryKey: KUNCI.persetujuan })
  } catch (err) {
    const kolom = errorKolom(err, ['peran_id', 'dasar_perubahan'])
    if (Object.keys(kolom).length) errorKolomIsian.value = kolom
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-penyetuju">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-penyetuju">Penyetuju izin sensitif</h2>
        <p>Pemilik data yang menyetujui pemberian izin sensitif. Izin tanpa penyetuju tidak bisa diberikan ke peran atau akun.</p>
      </div>
    </div>
    <DataTable
      :kolom="kolom"
      :baris="baris"
      :kunci-baris="(b: BarisIzin) => b.kode"
      label="Daftar izin sensitif"
      :memuat="pohon.isPending.value"
      :error="pohon.isError.value ? pesanError(pohon.error.value) : null"
      teks-kosong="Tidak ada izin sensitif."
      @coba-lagi="pohon.refetch()"
    >
      <template #sel-izin="{ baris: b }">
        <Ikon nama="gembok" :ukuran="12" class="gembok" />{{ b.label }}
        <code class="kode">{{ b.kode }}</code>
      </template>
      <template #sel-penyetuju="{ baris: b }">
        <template v-if="b.penyetuju">{{ b.penyetuju.nama }}</template>
        <span v-else class="pil pil-peringatan">Belum ditetapkan</span>
      </template>
      <template #sel-aksi="{ baris: b }">
        <Tombol :aria-label="`${b.penyetuju ? 'Ganti' : 'Tetapkan'} penyetuju ${b.label}`" @click="ganti(b)">{{ b.penyetuju ? 'Ganti' : 'Tetapkan' }}</Tombol>
      </template>
    </DataTable>

    <Dialog v-model:open="buka" :judul="`Penyetuju ${diganti?.label ?? ''}`" uraian="Perubahan penyetuju berlaku setelah disetujui superadmin lain." :sibuk="sibuk">
      <form id="form-penyetuju" novalidate @submit.prevent="kirim">
        <p v-if="diganti?.penyetuju" class="catatan catatan-netral sekarang">
          <Ikon nama="info" :ukuran="16" />
          <span>Penyetuju sekarang: <b>{{ diganti.penyetuju.nama }}</b>. Pengajuan yang sedang menunggu tetap memakai penyetuju lama.</span>
        </p>
        <KolomIsian v-slot="a" label="Peran penyetuju" :error="errorKolomIsian.peran_id" petunjuk="Tidak boleh peran yang Anda pegang." wajib>
          <select v-bind="a" v-model="peranId" class="isian">
            <option value="" disabled>{{ peranAktif.isPending.value ? 'Memuat peran…' : 'Pilih peran aktif' }}</option>
            <option v-for="p in peranAktif.data.value ?? []" :key="p.id" :value="p.id" :disabled="p.id === diganti?.penyetuju?.id">{{ p.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorKolomIsian.dasar_perubahan" petunjuk="Nomor SK atau keputusan pimpinan." wajib>
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
        </KolomIsian>
        <p v-if="error" class="catatan catatan-bahaya" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>{{ error }}</span>
        </p>
      </form>
      <template #aksi>
        <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
        <Tombol type="submit" form="form-penyetuju" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Mengajukan…' : 'Ajukan' }}</Tombol>
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.gembok {
  margin-right: 6px;
  vertical-align: -1px;
  color: var(--warn);
}
.kode {
  display: block;
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--muted);
}
.sekarang {
  margin: 0 0 var(--space-4);
}
</style>
