<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { batalkanAlergi, catatAlergi, daftarAlergi } from '../api'
import { KUNCI_WORKLIST, LABEL_JENIS_ALERGI, LABEL_TINGKAT, pesanKlinis } from '../rawatJalan'
import type { Alergi, JenisAlergi, TingkatAlergi } from '../tipe'

/**
 * Alergi pasien lintas kunjungan (FR-26-15): daftar yang aktif, catat baru, dan batalkan dengan alasan. Baris tidak dihapus;
 * pembatalan menyimpan alasan dan pelaku di server. Isi alergi tidak tampil di log.
 */
const props = defineProps<{ pasienId: string; bisaCatat: boolean; bisaBatal: boolean }>()
const qc = useQueryClient()

const kueri = useQuery({ queryKey: computed(() => KUNCI_WORKLIST.alergi(props.pasienId)), queryFn: async () => (await daftarAlergi(props.pasienId)).data })
const segarkan = () => {
  void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.alergi(props.pasienId) })
  void qc.invalidateQueries({ queryKey: KUNCI_WORKLIST.semua })
}

const zat = ref('')
const jenis = ref<JenisAlergi | ''>('')
const reaksi = ref('')
const tingkat = ref<TingkatAlergi | ''>('')
const catat = useMutation({
  mutationFn: () => catatAlergi(props.pasienId, { zat: zat.value.trim(), jenis: jenis.value as JenisAlergi, reaksi: reaksi.value.trim() || null, tingkat: tingkat.value || null }),
  onSuccess: (a) => {
    tampilkanToast(`Alergi ${a.zat} dicatat.`, 'ok')
    zat.value = reaksi.value = ''
    jenis.value = tingkat.value = ''
    segarkan()
  },
})
const bisaKirim = computed(() => zat.value.trim().length >= 2 && jenis.value !== '')

const sasaran = ref<Alergi | null>(null)
const alasan = ref('')
const batal = useMutation({
  mutationFn: () => batalkanAlergi(sasaran.value!.id, alasan.value.trim()),
  onSuccess: () => {
    tampilkanToast('Catatan alergi dibatalkan.', 'ok')
    sasaran.value = null
    segarkan()
  },
})
function mulaiBatal(a: Alergi) {
  sasaran.value = a
  alasan.value = ''
  batal.reset()
}
</script>

<template>
  <section class="panel-alergi" aria-labelledby="judul-alergi">
    <h3 id="judul-alergi">Alergi pasien</h3>
    <p v-if="kueri.isPending.value" class="ket" role="status">Memuat alergi…</p>
    <Pesan v-else-if="kueri.isError.value" jenis="error">Alergi tidak dapat dimuat: {{ pesanError(kueri.error.value) }}</Pesan>
    <p v-else-if="!kueri.data.value?.length" class="ket">Belum ada alergi tercatat. Tanyakan ke pasien atau keluarga, lalu catat di bawah; "tidak ada alergi" tidak dicatat sebagai entri.</p>
    <ul v-else class="daftar">
      <li v-for="a in kueri.data.value" :key="a.id">
        <div>
          <b>{{ a.zat }}</b>
          <Lencana jenis="peringatan">{{ LABEL_JENIS_ALERGI[a.jenis] }}</Lencana>
          <Lencana v-if="a.tingkat" :jenis="a.tingkat === 'berat' ? 'bahaya' : 'netral'">{{ LABEL_TINGKAT[a.tingkat] }}</Lencana>
          <span v-if="a.reaksi" class="ket">Reaksi: {{ a.reaksi }}</span>
          <span class="ket">Dicatat {{ a.dicatat_oleh.nama }}</span>
        </div>
        <Tombol v-if="bisaBatal" :aria-label="`Batalkan catatan alergi ${a.zat}`" @click="mulaiBatal(a)">Batalkan</Tombol>
      </li>
    </ul>

    <form v-if="sasaran" class="form-batal" @submit.prevent="batal.mutate()">
      <KolomIsian v-slot="k" :label="`Alasan membatalkan alergi ${sasaran.zat}`" wajib petunjuk="Minimal 5 karakter. Catatan tidak dihapus; alasan dan pelakunya tersimpan." :error="batal.isError.value ? pesanKlinis(batal.error.value) : undefined">
        <textarea v-bind="k" v-model="alasan" class="isian" rows="2" maxlength="500"></textarea>
      </KolomIsian>
      <div class="aksi">
        <Tombol @click="sasaran = null">Tidak jadi</Tombol>
        <Tombol type="submit" varian="bahaya" :disabled="alasan.trim().length < 5 || undefined" :sibuk="batal.isPending.value">Batalkan catatan</Tombol>
      </div>
    </form>

    <form v-if="bisaCatat" class="form-catat" @submit.prevent="catat.mutate()">
      <h4>Catat alergi baru</h4>
      <div class="grid">
        <KolomIsian v-slot="k" label="Zat" wajib>
          <input v-bind="k" v-model="zat" class="isian" maxlength="150" autocomplete="off" />
        </KolomIsian>
        <KolomIsian v-slot="k" label="Jenis" wajib>
          <select v-bind="k" v-model="jenis" class="isian">
            <option value="" disabled>Pilih jenis…</option>
            <option v-for="(label, kunci) in LABEL_JENIS_ALERGI" :key="kunci" :value="kunci">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="k" label="Tingkat">
          <select v-bind="k" v-model="tingkat" class="isian">
            <option value="">Belum dinilai</option>
            <option v-for="(label, kunci) in LABEL_TINGKAT" :key="kunci" :value="kunci">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="k" label="Reaksi">
          <input v-bind="k" v-model="reaksi" class="isian" maxlength="500" autocomplete="off" />
        </KolomIsian>
      </div>
      <Pesan v-if="catat.isError.value" jenis="error">{{ pesanKlinis(catat.error.value) }}</Pesan>
      <div class="aksi"><Tombol type="submit" varian="utama" :disabled="!bisaKirim || undefined" :sibuk="catat.isPending.value">Catat alergi</Tombol></div>
    </form>
  </section>
</template>

<style scoped>
.panel-alergi {
  display: grid;
  gap: var(--space-3);
}
h3,
h4 {
  margin: 0;
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
.daftar {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.daftar li {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-3);
}
.form-catat,
.form-batal {
  display: grid;
  gap: var(--space-3);
}
.aksi {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}
</style>
