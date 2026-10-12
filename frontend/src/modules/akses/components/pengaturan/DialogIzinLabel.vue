<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { formatTanggal, tanggalKeIso } from '@/shared/utils/waktu'
import { ajukanLabelAkun, ambilLabelSensitivitas } from '../../apiPengaturan'
import { errorKolom, KUNCI, pesanError } from '../../pengaturan'
import { teksMenunggu } from '../../persetujuan'

/**
 * Ajukan izin label sensitivitas untuk akun (tiket 1.5, kontrak v0.14.0): selalu menunggu persetujuan pejabat PDP
 * (peran penyetuju label itu) dan berbatas waktu, paling lama 365 hari (AKSES_LABEL_MAKS_HARI, diperiksa server). Label
 * tanpa penyetuju belum bisa diberikan.
 */
const props = defineProps<{ akunId: string; namaAkun: string }>()
const emit = defineEmits<{ selesai: [pesan: string] }>()
const buka = defineModel<boolean>('open', { required: true })

const MAKS_HARI = 365
const label = useQuery({ queryKey: KUNCI.labelSensitivitas, queryFn: ambilLabelSensitivitas, enabled: buka, staleTime: 60_000 })

function tanggalLokal(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const besok = computed(() => tanggalLokal(new Date(Date.now() + 86_400_000)))
// Izin berakhir pukul 23.59 tanggal terpilih, sedangkan server menolak bila lewat sekarang + 365 hari: tanggal terakhir
// yang selalu lolos adalah hari ke-364.
const batasAkhir = computed(() => tanggalLokal(new Date(Date.now() + (MAKS_HARI - 1) * 86_400_000)))

const sibuk = ref(false)
const error = ref<string | null>(null)
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({
  validationSchema: skemaZod(
    z.object({
      label_kode: z.string().min(1, 'Pilih label.'),
      berlaku_sampai: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Isi tanggal berakhir.'),
      dasar_pemberian: z.string().trim().min(3, 'Isi dasar pemberian, minimal 3 karakter.').max(200),
      alasan: z.string().trim().min(5, 'Tulis alasan, minimal 5 karakter.').max(500),
    }),
  ),
})
const [kodeLabel] = defineField('label_kode')
const [sampai] = defineField('berlaku_sampai')
const [dasar] = defineField('dasar_pemberian')
const [alasan] = defineField('alasan')

const terpilih = computed(() => label.data.value?.find((l) => l.kode === kodeLabel.value) ?? null)

watch(buka, (b) => {
  if (!b) return
  resetForm({ values: { label_kode: '', berlaku_sampai: '', dasar_pemberian: '', alasan: '' } })
  error.value = null
})

const kirim = handleSubmit(async (v) => {
  if (v.berlaku_sampai < besok.value || v.berlaku_sampai > batasAkhir.value) {
    setErrors({ berlaku_sampai: `Pilih tanggal antara besok dan ${formatTanggal(batasAkhir.value)} (paling lama ${MAKS_HARI} hari).` })
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const p = await ajukanLabelAkun(props.akunId, {
      label_kode: v.label_kode,
      berlaku_sampai: tanggalKeIso(v.berlaku_sampai, true)!,
      dasar_pemberian: v.dasar_pemberian.trim(),
      alasan: v.alasan.trim(),
    })
    buka.value = false
    emit('selesai', `Izin label ${terpilih.value?.nama ?? v.label_kode} untuk ${props.namaAkun} ${teksMenunggu(p)}.`)
  } catch (e) {
    const kolom = errorKolom(e, ['label_kode', 'berlaku_sampai', 'dasar_pemberian', 'alasan'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog
    v-model:open="buka"
    :judul="`Ajukan izin label untuk ${namaAkun}`"
    uraian="Izin label baru berlaku setelah disetujui pejabat PDP, dan selalu berakhir pada tanggal yang ditetapkan."
    :sibuk="sibuk"
    peringatan
  >
    <form id="form-izin-label" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Label sensitivitas" :error="errors.label_kode" wajib>
        <select v-bind="a" v-model="kodeLabel" class="isian">
          <option value="" disabled>{{ label.isPending.value ? 'Memuat label…' : 'Pilih label' }}</option>
          <option v-for="l in label.data.value ?? []" :key="l.kode" :value="l.kode" :disabled="!l.aktif || !l.penyetuju_peran">
            {{ l.nama }}{{ !l.aktif ? ' · nonaktif' : !l.penyetuju_peran ? ' · penyetuju belum ditetapkan' : '' }}
          </option>
        </select>
      </KolomIsian>
      <p v-if="label.isError.value" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ pesanError(label.error.value) }}</span>
      </p>
      <p v-else-if="label.data.value && !label.data.value.some((l) => l.aktif && l.penyetuju_peran)" class="catatan catatan-peringatan">
        <Ikon nama="info" :ukuran="16" />
        <span>Belum ada label yang punya penyetuju. Superadmin menetapkannya di Persetujuan akses → Penyetuju label.</span>
      </p>
      <p v-if="terpilih" class="catatan catatan-netral">
        <Ikon nama="info" :ukuran="16" />
        <span>
          Disetujui oleh pemegang peran <b>{{ terpilih.penyetuju_peran?.nama }}</b>.
          <template v-if="terpilih.butuh_relasi_perawatan"> Data berlabel tetap hanya terbuka untuk pasien yang dirawat akun ini.</template>
        </span>
      </p>
      <KolomIsian v-slot="a" label="Berlaku sampai" :error="errors.berlaku_sampai" :petunjuk="`Paling lama ${MAKS_HARI} hari. Izin berakhir pukul 23.59 tanggal itu.`" wajib>
        <input v-bind="a" v-model="sampai" type="date" class="isian" :min="besok" :max="batasAkhir" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar pemberian" :error="errors.dasar_pemberian" petunjuk="Nomor SK, surat tugas, atau memo." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Alasan" :error="errors.alasan" petunjuk="Mengapa akun ini perlu membuka data berlabel." wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="3" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-izin-label" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Mengajukan…' : 'Ajukan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.catatan {
  margin: 0 0 var(--space-3);
}
</style>
