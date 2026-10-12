<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { ambilLabelSensitivitas } from '../../apiPengaturan'
import { aturPenyetujuLabel } from '../../apiPersetujuan'
import { errorKolom, KUNCI, pesanError, usePeranAktif } from '../../pengaturan'
import { teksMenunggu } from '../../persetujuan'
import type { LabelSensitivitas } from '../../tipePengaturan'

/**
 * Penyetuju (pejabat PDP) tiap label sensitivitas (tiket 1.5, kontrak v0.14.0). Label tanpa penyetuju tidak bisa
 * diberikan ke akun mana pun. Menetapkan/mengganti penyetuju selalu menunggu persetujuan superadmin lain (K13).
 */
const qc = useQueryClient()
const label = useQuery({ queryKey: KUNCI.labelSensitivitas, queryFn: ambilLabelSensitivitas })
const peranAktif = usePeranAktif()
const bolehUbah = computed(() => boleh('pengaturan.peran.perbaiki'))

const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'label', label: 'Label' },
  { kunci: 'penyetuju', label: 'Penyetuju (pejabat PDP)', lebar: '240px' },
  ...(bolehUbah.value ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '140px' }] : []),
])

const diganti = ref<LabelSensitivitas | null>(null)
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
function ganti(l: LabelSensitivitas) {
  diganti.value = l
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
    const p = await aturPenyetujuLabel(diganti.value.kode, { peran_id: peranId.value, dasar_perubahan: dasar.value.trim() })
    buka.value = false
    tampilkanToast(`Penyetuju label ${diganti.value.nama} ${teksMenunggu(p)}.`)
    void qc.invalidateQueries({ queryKey: KUNCI.labelSensitivitas })
    void qc.invalidateQueries({ queryKey: KUNCI.persetujuan })
  } catch (err) {
    const k = errorKolom(err, ['peran_id', 'dasar_perubahan'])
    if (Object.keys(k).length) errorKolomIsian.value = k
    else error.value = pesanError(err)
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-penyetuju-label">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-penyetuju-label">Penyetuju label sensitivitas</h2>
        <p>Pejabat PDP yang menyetujui izin label untuk akun. Label tanpa penyetuju tidak bisa diberikan.</p>
      </div>
    </div>
    <DataTable
      :kolom="kolom"
      :baris="label.data.value ?? []"
      :kunci-baris="(l: LabelSensitivitas) => l.kode"
      label="Daftar label sensitivitas"
      :memuat="label.isPending.value"
      :error="label.isError.value ? pesanError(label.error.value) : null"
      teks-kosong="Belum ada label sensitivitas."
      @coba-lagi="label.refetch()"
    >
      <template #sel-label="{ baris: l }">
        <Ikon nama="gembok" :ukuran="12" class="gembok" />{{ l.nama }}
        <span v-if="!l.aktif" class="pil pil-netral">nonaktif</span>
        <span class="uraian">{{ l.uraian ?? '' }}{{ l.butuh_relasi_perawatan ? `${l.uraian ? ' · ' : ''}butuh relasi perawatan` : '' }}</span>
      </template>
      <template #sel-penyetuju="{ baris: l }">
        <template v-if="l.penyetuju_peran">{{ l.penyetuju_peran.nama }}</template>
        <span v-else class="pil pil-peringatan">Belum ditetapkan</span>
      </template>
      <template #sel-aksi="{ baris: l }">
        <Tombol :aria-label="`${l.penyetuju_peran ? 'Ganti' : 'Tetapkan'} penyetuju label ${l.nama}`" @click="ganti(l)">{{ l.penyetuju_peran ? 'Ganti' : 'Tetapkan' }}</Tombol>
      </template>
    </DataTable>

    <Dialog v-model:open="buka" :judul="`Penyetuju label ${diganti?.nama ?? ''}`" uraian="Perubahan penyetuju berlaku setelah disetujui superadmin lain." :sibuk="sibuk">
      <form id="form-penyetuju-label" novalidate @submit.prevent="kirim">
        <p v-if="diganti?.penyetuju_peran" class="catatan catatan-netral sekarang">
          <Ikon nama="info" :ukuran="16" />
          <span>Penyetuju sekarang: <b>{{ diganti.penyetuju_peran.nama }}</b>. Pengajuan izin label yang sedang menunggu tetap memakai penyetuju lama.</span>
        </p>
        <KolomIsian v-slot="a" label="Peran penyetuju" :error="errorKolomIsian.peran_id" petunjuk="Peran pejabat PDP. Tidak boleh peran yang Anda pegang." wajib>
          <select v-bind="a" v-model="peranId" class="isian">
            <option value="" disabled>{{ peranAktif.isPending.value ? 'Memuat peran…' : 'Pilih peran aktif' }}</option>
            <option v-for="p in peranAktif.data.value ?? []" :key="p.id" :value="p.id" :disabled="p.id === diganti?.penyetuju_peran?.id">{{ p.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Dasar perubahan" :error="errorKolomIsian.dasar_perubahan" petunjuk="Nomor SK penunjukan pejabat PDP." wajib>
          <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
        </KolomIsian>
        <p v-if="error" class="catatan catatan-bahaya" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>{{ error }}</span>
        </p>
      </form>
      <template #aksi>
        <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
        <Tombol type="submit" form="form-penyetuju-label" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Mengajukan…' : 'Ajukan' }}</Tombol>
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
.uraian {
  display: block;
  font-size: 12.5px;
  color: var(--muted);
}
.sekarang {
  margin: 0 0 var(--space-4);
}
</style>
