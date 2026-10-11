<script setup lang="ts">
import { useForm } from 'vee-validate'
import { ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { tanggalKeIso } from '@/shared/utils/waktu'
import { tugaskanPeran } from '../../apiPengaturan'
import PemilihUnit from '@/modules/organisasi/components/PemilihUnit.vue'
import { errorKolom, pesanError, usePeranAktif } from '../../pengaturan'

/**
 * Tugaskan peran ke akun (FR-09-04): peran aktif, cakupan, masa berlaku, dasar pemberian wajib. Cakupan Seluruh RS
 * atau Unit kerja (tiket 1.8a: unit induk mencakup turunannya, jadi ruang/poli/depo dipilih sebagai unit). Cakupan
 * lain tampil nonaktif sampai didukung kontrak.
 */
const props = defineProps<{ akunId: string; namaAkun: string }>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [] }>()

const peranAktif = usePeranAktif()
const sibuk = ref(false)
const error = ref<string | null>(null)

const CAKUPAN_LAIN = [
  ['ksm', 'KSM'],
  ['pasien_dirawat', 'Pasien yang dirawat'],
] as const

const skema = z
  .object({
    peran_id: z.string().min(1, 'Pilih peran.'),
    cakupan_jenis: z.enum(['rs', 'unit']),
    cakupan_id: z.string().nullable(),
    berlaku_dari: z.string().optional(),
    berlaku_sampai: z.string().optional(),
    dasar_pemberian: z.string().trim().min(3, 'Isi dasar pemberian (nomor SK atau surat tugas), minimal 3 karakter.').max(200),
    alasan: z.string().trim().max(500).optional(),
  })
  .refine((v) => v.cakupan_jenis === 'rs' || !!v.cakupan_id, { path: ['cakupan_id'], message: 'Pilih unit kerja.' })
  .refine((v) => !v.berlaku_dari || !v.berlaku_sampai || v.berlaku_sampai >= v.berlaku_dari, {
    path: ['berlaku_sampai'],
    message: 'Tanggal berakhir tidak boleh sebelum tanggal mulai.',
  })
const { defineField, handleSubmit, errors, resetForm, setErrors } = useForm({ validationSchema: skemaZod(skema) })
const [peranId] = defineField('peran_id')
const [cakupanJenis] = defineField('cakupan_jenis')
const [cakupanId] = defineField<'cakupan_id', string | null>('cakupan_id')
const [dari] = defineField('berlaku_dari')
const [sampai] = defineField('berlaku_sampai')
const [dasar] = defineField('dasar_pemberian')
const [alasan] = defineField('alasan')

watch(buka, (b) => {
  if (!b) return
  error.value = null
  resetForm({ values: { peran_id: '', cakupan_jenis: 'rs', cakupan_id: null, berlaku_dari: '', berlaku_sampai: '', dasar_pemberian: '', alasan: '' } })
})

const kirim = handleSubmit(async (v) => {
  sibuk.value = true
  error.value = null
  try {
    await tugaskanPeran(props.akunId, {
      peran_id: v.peran_id,
      cakupan_jenis: v.cakupan_jenis,
      cakupan_id: v.cakupan_jenis === 'unit' ? v.cakupan_id : null,
      berlaku_dari: v.berlaku_dari ? tanggalKeIso(v.berlaku_dari) : null,
      berlaku_sampai: v.berlaku_sampai ? tanggalKeIso(v.berlaku_sampai, true) : null,
      dasar_pemberian: v.dasar_pemberian,
      alasan: v.alasan ? v.alasan : null,
    })
    buka.value = false
    emit('selesai')
  } catch (e) {
    const kolom = errorKolom(e, ['peran_id', 'cakupan_id', 'berlaku_dari', 'berlaku_sampai', 'dasar_pemberian', 'alasan'])
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Tugaskan peran ke ${namaAkun}`" :sibuk="sibuk">
    <form id="form-penugasan" novalidate @submit.prevent="kirim">
      <KolomIsian v-slot="a" label="Peran" :error="errors.peran_id" wajib>
        <select v-bind="a" v-model="peranId" class="isian" :disabled="peranAktif.isPending.value">
          <option value="" disabled>{{ peranAktif.isPending.value ? 'Memuat peran…' : 'Pilih peran aktif' }}</option>
          <!-- Peran berizin sensitif butuh persetujuan pemilik data (409 butuh_persetujuan sampai tiket 1.2d). -->
          <option v-for="p in peranAktif.data.value ?? []" :key="p.id" :value="p.id" :disabled="p.memuat_izin_sensitif">
            {{ p.nama }}{{ p.wajib_mfa ? ' · wajib MFA' : '' }}{{ p.memuat_izin_sensitif ? ' · butuh persetujuan pemilik data' : '' }}
          </option>
        </select>
      </KolomIsian>

      <fieldset class="cakupan">
        <legend>Cakupan</legend>
        <label class="pilihan"><input v-model="cakupanJenis" type="radio" name="cakupan" value="rs" /> Seluruh RS</label>
        <label class="pilihan"><input v-model="cakupanJenis" type="radio" name="cakupan" value="unit" /> Unit kerja</label>
        <label v-for="[nilai, label] in CAKUPAN_LAIN" :key="nilai" class="pilihan nonaktif">
          <input type="radio" name="cakupan" :value="nilai" disabled aria-describedby="ket-cakupan" /> {{ label }}
        </label>
        <p id="ket-cakupan" class="ket">Ruang, poli, dan depo dipilih sebagai unit kerja; unit induk mencakup semua turunannya. Cakupan KSM dan pasien yang dirawat menyusul.</p>
      </fieldset>
      <KolomIsian v-if="cakupanJenis === 'unit'" v-slot="a" label="Unit kerja" :error="errors.cakupan_id" wajib>
        <PemilihUnit v-bind="a" v-model="cakupanId" />
      </KolomIsian>

      <div class="dua-kolom">
        <KolomIsian v-slot="a" label="Berlaku dari" :error="errors.berlaku_dari" petunjuk="Kosong = mulai sekarang.">
          <input v-bind="a" v-model="dari" type="date" class="isian" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Berlaku sampai" :error="errors.berlaku_sampai" petunjuk="Kosong = tidak dibatasi.">
          <input v-bind="a" v-model="sampai" type="date" class="isian" />
        </KolomIsian>
      </div>
      <KolomIsian v-slot="a" label="Dasar pemberian" :error="errors.dasar_pemberian" petunjuk="Nomor SK, surat tugas, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Alasan (opsional)" :error="errors.alasan">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-penugasan" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Tugaskan' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.cakupan {
  margin: 0 0 var(--space-4);
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
  font-size: 13px;
}
.pilihan input {
  accent-color: var(--brand-600);
}
.pilihan.nonaktif {
  color: var(--subtle);
}
.ket {
  margin: 2px 0 0;
  font-size: 12px;
  color: var(--muted);
}
.dua-kolom {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0 var(--space-3);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .pilihan {
    min-height: 44px;
  }
}
</style>
