<script setup lang="ts">
import { useForm } from 'vee-validate'
import { computed, ref, watch } from 'vue'
import { z } from 'zod'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { skemaZod } from '@/design-system/form/skemaZod'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { tanggalKeIso } from '@/shared/utils/waktu'
import { buatIzinKhusus } from '../../apiPengaturan'
import type { IndeksMatriks } from '../../matriks'
import { teksMenunggu } from '../../persetujuan'
import { errorKolom, pesanError } from '../../pengaturan'
import type { PermintaanAkses } from '../../tipePersetujuan'

/**
 * Pengecualian per akun (FR-09-13): tambah atau cabut satu sub-menu/bagian (seluruh halaman) atau satu aksi.
 * Cabut selalu menang atas peran. Menambah izin sensitif wajib berbatas waktu (PRD 9.2.3) dan menjadi persetujuan
 * menunggu pemilik datanya (202, tiket 1.2d). Sensitif hanya dinilai pada sasaran aksi, sama dengan server.
 * `permintaan`: menindaklanjuti permintaan akses (sasaran dan dasar = nomor permintaan diisi lebih dulu).
 */
const props = withDefaults(defineProps<{ akunId: string; namaAkun: string; indeks: IndeksMatriks; permintaan?: PermintaanAkses | null }>(), { permintaan: null })
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [pesan: string] }>()

interface Sasaran {
  jenis: 'menu' | 'izin'
  kode: string
  label: string
  sensitif: boolean
}

const semuaSasaran = computed<Sasaran[]>(() => {
  const hasil: Sasaran[] = []
  for (const b of props.indeks.baris) {
    if (b.node.tingkat === 'menu' || b.node.ikut_induk) continue
    hasil.push({ jenis: 'menu', kode: b.node.kode, label: `${b.jalur} (seluruh halaman)`, sensitif: false })
    for (const i of b.node.izin) {
      hasil.push({ jenis: 'izin', kode: i.kode, label: props.indeks.perKode.get(i.kode)?.label ?? i.kode, sensitif: i.sensitif })
    }
  }
  return hasil
})

const cari = ref('')
const BATAS_TAMPIL = 40
const cocok = computed(() => {
  const q = cari.value.trim().toLowerCase()
  const daftar = q ? semuaSasaran.value.filter((s) => s.label.toLowerCase().includes(q) || s.kode.includes(q)) : semuaSasaran.value
  return { daftar: daftar.slice(0, BATAS_TAMPIL), lebih: Math.max(0, daftar.length - BATAS_TAMPIL) }
})

const sibuk = ref(false)
const error = ref<string | null>(null)
const skema = z.object({
  efek: z.enum(['tambah', 'cabut']),
  sasaran: z.string().min(1, 'Pilih sub-menu, bagian, atau aksi.'),
  berlaku_sampai: z.string().optional(),
  dasar_pemberian: z.string().trim().min(3, 'Isi dasar (nomor SK, memo, atau persetujuan), minimal 3 karakter.').max(200),
  alasan: z.string().trim().min(5, 'Tulis alasan, minimal 5 karakter.').max(500),
})
const { defineField, handleSubmit, errors, resetForm, setErrors, values } = useForm({ validationSchema: skemaZod(skema) })
const [efek] = defineField('efek')
const [sasaran] = defineField('sasaran')
const [sampai] = defineField('berlaku_sampai')
const [dasar] = defineField('dasar_pemberian')
const [alasan] = defineField('alasan')

const dipilih = computed(() => semuaSasaran.value.find((s) => `${s.jenis}:${s.kode}` === values.sasaran) ?? null)
const wajibBatas = computed(() => (dipilih.value?.sensitif ?? false) && values.efek === 'tambah')

watch(buka, (b) => {
  if (!b) return
  error.value = null
  const pm = props.permintaan
  // Tindak lanjut: sasaran permintaan langsung terlihat (daftar dibatasi 40 butir).
  cari.value = pm?.sasaran_kode ?? ''
  resetForm({
    values: pm
      ? { efek: 'tambah', sasaran: `${pm.sasaran_jenis}:${pm.sasaran_kode}`, berlaku_sampai: '', dasar_pemberian: pm.nomor, alasan: pm.alasan.length >= 5 ? pm.alasan : '' }
      : { efek: 'cabut', sasaran: '', berlaku_sampai: '', dasar_pemberian: '', alasan: '' },
  })
})

const kirim = handleSubmit(async (v) => {
  const s = dipilih.value
  if (!s) return
  if (wajibBatas.value && !v.berlaku_sampai) {
    setErrors({ berlaku_sampai: 'Wajib diisi untuk sasaran sensitif.' })
    return
  }
  sibuk.value = true
  error.value = null
  try {
    const hasil = await buatIzinKhusus(props.akunId, {
      efek: v.efek,
      sasaran_jenis: s.jenis,
      sasaran_kode: s.kode,
      berlaku_sampai: v.berlaku_sampai ? tanggalKeIso(v.berlaku_sampai, true) : null,
      dasar_pemberian: v.dasar_pemberian,
      alasan: v.alasan,
      permintaan_akses_id: props.permintaan?.id ?? null,
    })
    buka.value = false
    emit('selesai', hasil.menunggu ? `Pengecualian ${s.label} ${teksMenunggu(hasil.persetujuan)}.` : 'Pengecualian disimpan.')
  } catch (e) {
    const kolom = errorKolom(e, ['sasaran', 'berlaku_sampai', 'dasar_pemberian', 'alasan'], { sasaran_kode: 'sasaran' })
    if (Object.keys(kolom).length) setErrors(kolom)
    else error.value = pesanError(e)
  } finally {
    sibuk.value = false
  }
})
</script>

<template>
  <Dialog v-model:open="buka" :judul="`Pengecualian untuk ${namaAkun}`" uraian="Tambah atau cabut akses satu akun di luar perannya. Cabut selalu menang." :sibuk="sibuk" lebar="lebar">
    <form id="form-izin-khusus" novalidate @submit.prevent="kirim">
      <p v-if="permintaan" class="catatan catatan-info tindak-lanjut">
        <Ikon nama="info" :ukuran="16" />
        <span>Menindaklanjuti permintaan {{ permintaan.nomor }}: <b>{{ permintaan.sasaran_label }}</b>. Alasan pemohon: {{ permintaan.alasan }}</span>
      </p>
      <fieldset class="efek">
        <legend>Jenis</legend>
        <label class="pilihan"><input v-model="efek" type="radio" value="cabut" /> Cabut</label>
        <label class="pilihan"><input v-model="efek" type="radio" value="tambah" /> Tambah</label>
      </fieldset>

      <fieldset class="sasaran" :aria-invalid="errors.sasaran ? true : undefined" aria-describedby="ket-sasaran">
        <legend>Sasaran <span class="wajib" aria-hidden="true">*</span></legend>
        <label class="sr-only" for="cari-sasaran">Cari sasaran</label>
        <input id="cari-sasaran" v-model="cari" type="search" class="isian" placeholder="Cari menu, bagian, atau aksi" autocomplete="off" />
        <div class="daftar-sasaran">
          <label v-for="s in cocok.daftar" :key="`${s.jenis}:${s.kode}`" class="opsi">
            <input v-model="sasaran" type="radio" name="sasaran" :value="`${s.jenis}:${s.kode}`" />
            <span>{{ s.label }}</span>
            <span v-if="s.sensitif" class="pil pil-peringatan"><Ikon nama="gembok" :ukuran="12" />sensitif</span>
          </label>
          <p v-if="cocok.daftar.length === 0" class="ket">Tidak ada yang cocok.</p>
          <p v-else-if="cocok.lebih" class="ket">{{ cocok.lebih }} lainnya; persempit pencarian.</p>
        </div>
        <p id="ket-sasaran" class="ket">Pilih halaman (seluruh sub-menu atau bagian) atau satu aksi.</p>
        <p v-if="errors.sasaran" class="error">{{ errors.sasaran }}</p>
      </fieldset>

      <p v-if="dipilih?.sensitif && efek === 'tambah'" class="catatan catatan-peringatan">
        <Ikon nama="gembok" :ukuran="16" />
        <span>Menambah sasaran sensitif tidak langsung berlaku: menunggu persetujuan pemilik datanya (penyetuju izin itu).</span>
      </p>

      <KolomIsian
        v-slot="a"
        label="Berlaku sampai"
        :error="errors.berlaku_sampai"
        :petunjuk="wajibBatas ? 'Wajib saat menambah izin sensitif.' : 'Kosong = sampai diakhiri.'"
        :wajib="wajibBatas"
      >
        <input v-bind="a" v-model="sampai" type="date" class="isian" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dasar" :error="errors.dasar_pemberian" petunjuk="Nomor SK, memo, atau persetujuan." wajib>
        <input v-bind="a" v-model="dasar" class="isian" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Alasan" :error="errors.alasan" wajib>
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" />
      </KolomIsian>
      <p v-if="error" class="catatan catatan-bahaya" role="alert">
        <Ikon nama="peringatan" :ukuran="16" />
        <span>{{ error }}</span>
      </p>
    </form>
    <template #aksi>
      <button type="button" class="tombol" :disabled="sibuk" @click="buka = false">Batal</button>
      <Tombol type="submit" form="form-izin-khusus" varian="utama" :sibuk="sibuk">{{ sibuk ? 'Menyimpan…' : 'Simpan pengecualian' }}</Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.tindak-lanjut {
  margin: 0 0 var(--space-4);
}
fieldset {
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
.wajib,
.error {
  color: var(--danger);
}
.error {
  margin: 4px 0 0;
  font-size: 12.5px;
}
.pilihan {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  margin-right: var(--space-4);
}
.pilihan input,
.opsi input {
  accent-color: var(--brand-600);
}
.daftar-sasaran {
  max-height: 220px;
  overflow-y: auto;
  margin-top: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.opsi {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 36px;
  padding: 4px 10px;
  border-bottom: 1px solid var(--border);
  font-size: 13px;
  cursor: pointer;
}
.opsi:last-child {
  border-bottom: 0;
}
.opsi:hover {
  background: var(--bg);
}
.opsi .pil {
  margin-left: auto;
}
.ket {
  margin: 4px 0 0;
  padding: 0 2px;
  font-size: 12px;
  color: var(--muted);
}
.daftar-sasaran .ket {
  padding: var(--space-2) 10px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .pilihan,
  .opsi {
    min-height: 44px;
  }
}
</style>
