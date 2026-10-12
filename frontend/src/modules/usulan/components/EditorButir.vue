<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { ambilKamar, ambilTempatTidur, semuaBangsalAktif, semuaKelasRawatAktif } from '@/modules/organisasi/apiLokasi'
import PemilihLokasi from '@/modules/organisasi/components/PemilihLokasi.vue'
import PemilihUnit from '@/modules/organisasi/components/PemilihUnit.vue'
import { DAFTAR_FUNGSI, KUNCI_LOKASI, LABEL_JENIS_KELAMIN, LABEL_STATUS_LOKASI } from '@/modules/organisasi/lokasi'
import type { Lokasi } from '@/modules/organisasi/tipeLokasi'
import { LABEL_AKSI_BUTIR, PILIHAN_BUTIR } from '../usulan'
import type { AksiUsulan, ButirUsulan, IsianButir, ObjekDataInduk } from '../tipe'

/**
 * Satu butir perubahan usulan (kontrak v0.9.0 IsianPerubahanUsulan): pilih jenis perubahan lalu objeknya dan nilai
 * baru. Untuk ubah, nilai saat ini dimuat dari 1.8b dan hanya isian yang berbeda dikirim (`nilai_lama` dipotret
 * server). Aturan 1.8b diperiksa server (uji kering); galat per butir dari `rincian` (`perubahan.<i>[.<isian>]`)
 * diteruskan lewat prop `galat`. `bentuk()` mengembalikan isian butir atau pesan galat klien.
 */
const props = defineProps<{ nomor: number; awal?: ButirUsulan | null; galat?: Record<string, string> }>()
const emit = defineEmits<{ hapus: [] }>()

type Kunci = (typeof PILIHAN_BUTIR)[number]
const kunci = ref<Kunci | ''>('')
const objekId = ref<string | null>(null)
const labelObjek = ref<string | null>(null)
const lokasiId = ref<string | null>(null)
const labelLokasi = ref<string | null>(null)
const nilai = ref<Record<string, unknown>>({})
const sekarang = ref<Record<string, unknown>>({})
const memuat = ref(false)
const galatMuat = ref<string | null>(null)
const labelUnit = ref<string | null>(null)

const jenisObjek = computed(() => (kunci.value ? (kunci.value.split('.')[0] as ObjekDataInduk) : null))
const aksi = computed(() => (kunci.value ? (kunci.value.split('.')[1] as AksiUsulan) : null))
const kelas = useQuery({ queryKey: KUNCI_LOKASI.kelasAktif, queryFn: semuaKelasRawatAktif, enabled: computed(() => jenisObjek.value === 'tempat_tidur' && aksi.value !== 'tutup'), staleTime: 60_000 })
const bangsal = useQuery({ queryKey: KUNCI_LOKASI.bangsalAktif, queryFn: semuaBangsalAktif, enabled: computed(() => jenisObjek.value === 'kamar'), staleTime: 60_000 })

/** Prefill dari butir lama (kirim ulang). */
watch(
  () => props.awal,
  (b) => {
    if (!b) return
    kunci.value = `${b.objek_jenis}.${b.aksi}` as Kunci
    objekId.value = b.objek_id
    labelObjek.value = b.objek_label
    nilai.value = { ...b.nilai_baru }
    sekarang.value = { ...(b.nilai_lama ?? {}) }
    const t = (isian: string) => b.tampilan.find((x) => x.isian === isian)?.baru ?? null
    if (b.aksi === 'tambah' && b.objek_jenis === 'tempat_tidur') labelLokasi.value = t('kamar_id')
    if (b.aksi === 'tambah' && b.objek_jenis === 'kamar') labelLokasi.value = t('lokasi_id')
    if (b.objek_jenis === 'ruang' && b.aksi === 'tambah') labelLokasi.value = t('induk_id')
    labelUnit.value = t('unit_organisasi_id')
  },
  { immediate: true },
)
function gantiJenis() {
  objekId.value = null
  labelObjek.value = null
  lokasiId.value = null
  labelLokasi.value = null
  nilai.value = kunci.value === 'tempat_tidur.tambah' ? { aturan_jenis_kelamin: 'campur', isolasi: false, ekstra: false } : {}
  sekarang.value = {}
  galatMuat.value = null
}

/** Objek untuk ubah/tutup dipilih lewat lokasinya (ruang/TT); nilai saat ini dimuat dari 1.8b. */
async function pilihObjek(l: Lokasi | null) {
  galatMuat.value = null
  objekId.value = null
  sekarang.value = {}
  if (!l || !jenisObjek.value) return
  memuat.value = true
  try {
    if (jenisObjek.value === 'tempat_tidur') {
      if (!l.tempat_tidur_id) throw new Error('Lokasi ini bukan tempat tidur.')
      const t = (await ambilTempatTidur(l.tempat_tidur_id)).data
      objekId.value = t.id
      labelObjek.value = `TT ${t.kode}`
      sekarang.value = { kelas_rawat_id: t.kelas_rawat.id, aturan_jenis_kelamin: t.aturan_jenis_kelamin, isolasi: t.isolasi, ekstra: t.ekstra }
    } else if (jenisObjek.value === 'kamar') {
      if (!l.kamar_id) throw new Error('Ruang ini belum menjadi kamar rawat.')
      const k = (await ambilKamar(l.kamar_id)).data
      objekId.value = k.id
      labelObjek.value = `Kamar ${l.kode_lengkap}`
      sekarang.value = { bangsal_id: k.bangsal.id, kapasitas_maksimum: k.kapasitas_maksimum }
    } else {
      objekId.value = l.id
      labelObjek.value = `${l.kode_lengkap} · ${l.nama}`
      sekarang.value = { nama: l.nama, fungsi: l.fungsi, unit_organisasi_id: l.unit?.id ?? null, status: l.status }
      labelUnit.value = l.unit ? `${l.unit.kode} · ${l.unit.nama}` : null
    }
    if (aksi.value === 'ubah') nilai.value = { ...sekarang.value }
  } catch (e) {
    galatMuat.value = e instanceof Error && !('status' in e) ? e.message : pesanError(e)
  } finally {
    memuat.value = false
  }
}
/** Kamar untuk TT baru: ruang yang sudah menjadi kamar rawat. */
function pilihKamarTt(l: Lokasi | null) {
  galatMuat.value = null
  nilai.value = { ...nilai.value, kamar_id: l?.kamar_id ?? null }
  if (l && !l.kamar_id) galatMuat.value = 'Ruang ini belum menjadi kamar rawat; ajukan "Jadikan ruang kamar rawat" lebih dulu (usulan terpisah).'
}
function pilihRuangKamar(l: Lokasi | null) {
  galatMuat.value = null
  nilai.value = { ...nilai.value, lokasi_id: l?.id ?? null }
  if (l?.kamar_id) galatMuat.value = 'Ruang ini sudah menjadi kamar rawat.'
}

const set = (k: string, v: unknown) => (nilai.value = { ...nilai.value, [k]: v })
const teksAtauNull = (v: unknown) => (typeof v === 'string' && v.trim() ? v.trim() : null)

/** Isian butir siap kirim, atau pesan galat klien. */
function bentuk(): IsianButir | string {
  if (!kunci.value || !jenisObjek.value || !aksi.value) return 'Pilih jenis perubahan.'
  if (galatMuat.value) return galatMuat.value
  const n = nilai.value
  if (aksi.value === 'tambah') {
    if (kunci.value === 'tempat_tidur.tambah') {
      if (!n.kamar_id) return 'Pilih kamar rawat.'
      if (!/^[A-Z0-9]{1,10}$/.test(String(n.kode ?? '').trim().toUpperCase())) return 'Kode TT 1–10 huruf besar atau angka.'
      if (!n.kelas_rawat_id) return 'Pilih kelas rawat.'
      return { objek_jenis: 'tempat_tidur', aksi: 'tambah', objek_id: null, nilai_baru: { kamar_id: n.kamar_id, kode: String(n.kode).trim().toUpperCase(), kelas_rawat_id: n.kelas_rawat_id, aturan_jenis_kelamin: n.aturan_jenis_kelamin ?? 'campur', isolasi: !!n.isolasi, ekstra: !!n.ekstra } }
    }
    if (kunci.value === 'kamar.tambah') {
      if (!n.lokasi_id) return 'Pilih ruang.'
      if (!n.bangsal_id) return 'Pilih bangsal.'
      const kap = String(n.kapasitas_maksimum ?? '').trim()
      if (kap && !/^\d{1,2}$/.test(kap)) return 'Kapasitas 1–99, atau kosong.'
      return { objek_jenis: 'kamar', aksi: 'tambah', objek_id: null, nilai_baru: { bangsal_id: n.bangsal_id, lokasi_id: n.lokasi_id, kapasitas_maksimum: kap ? Number(kap) : null, atribut_standar: {} } }
    }
    if (!n.induk_id) return 'Pilih lantai atau sayap induk.'
    if (!/^[A-Z0-9]{1,10}$/.test(String(n.kode ?? '').trim().toUpperCase())) return 'Kode ruang 1–10 huruf besar atau angka.'
    if (String(n.nama ?? '').trim().length < 2) return 'Nama ruang minimal 2 karakter.'
    if (!n.fungsi) return 'Pilih fungsi ruang.'
    return { objek_jenis: 'ruang', aksi: 'tambah', objek_id: null, nilai_baru: { induk_id: n.induk_id, kode: String(n.kode).trim().toUpperCase(), nama: String(n.nama).trim(), fungsi: n.fungsi, unit_organisasi_id: n.unit_organisasi_id ?? null } }
  }
  if (!objekId.value) return 'Pilih objek yang diubah.'
  if (aksi.value === 'tutup') {
    if (String(n.alasan ?? '').trim().length < 5) return 'Isi alasan penutupan, minimal 5 karakter.'
    return { objek_jenis: jenisObjek.value, aksi: 'tutup', objek_id: objekId.value, nilai_baru: { alasan: String(n.alasan).trim() } }
  }
  // Ubah: hanya isian yang berbeda dari nilai saat ini.
  const normal = (k: string, v: unknown) => (k === 'kapasitas_maksimum' ? (String(v ?? '').trim() === '' ? null : Number(v)) : k === 'nama' ? teksAtauNull(v) : (v ?? null))
  const beda = Object.fromEntries(Object.keys(n).filter((k) => JSON.stringify(normal(k, n[k])) !== JSON.stringify(sekarang.value[k] ?? null)).map((k) => [k, normal(k, n[k])]))
  if (!Object.keys(beda).length) return 'Belum ada nilai yang diubah.'
  return { objek_jenis: jenisObjek.value, aksi: 'ubah', objek_id: objekId.value, nilai_baru: beda }
}
defineExpose({ bentuk })
const g = (isian?: string) => props.galat?.[isian ? `perubahan.${props.nomor}.${isian}` : `perubahan.${props.nomor}`]
</script>

<template>
  <fieldset class="butir">
    <legend>Perubahan {{ nomor + 1 }}</legend>
    <div class="baris-atas">
      <KolomIsian v-slot="a" label="Jenis perubahan" :error="g()" wajib>
        <select v-bind="a" v-model="kunci" class="isian" @change="gantiJenis">
          <option value="" disabled>Pilih</option>
          <option v-for="k in PILIHAN_BUTIR" :key="k" :value="k">{{ LABEL_AKSI_BUTIR[k] }}</option>
        </select>
      </KolomIsian>
      <button type="button" class="tombol hapus" :aria-label="`Hapus perubahan ${nomor + 1}`" @click="emit('hapus')">Hapus</button>
    </div>

    <template v-if="kunci === 'tempat_tidur.tambah'">
      <KolomIsian v-slot="a" label="Kamar rawat (ruang)" :error="g('kamar_id')" wajib>
        <PemilihLokasi v-bind="a" v-model="lokasiId" :jenis="['ruang']" :label-terpilih="labelLokasi" @pilih="pilihKamarTt" />
      </KolomIsian>
      <div class="kisi">
        <KolomIsian v-slot="a" label="Kode TT" :error="g('kode')" wajib>
          <input v-bind="a" :value="nilai.kode ?? ''" class="isian kode" maxlength="10" autocomplete="off" @input="set('kode', ($event.target as HTMLInputElement).value.toUpperCase())" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kelas rawat" :error="g('kelas_rawat_id')" wajib>
          <select v-bind="a" :value="nilai.kelas_rawat_id ?? ''" class="isian" @change="set('kelas_rawat_id', ($event.target as HTMLSelectElement).value)">
            <option value="" disabled>Pilih</option>
            <option v-for="k in kelas.data.value ?? []" :key="k.id" :value="k.id">{{ k.kode }} · {{ k.nama }}</option>
          </select>
        </KolomIsian>
      </div>
    </template>

    <template v-else-if="kunci === 'tempat_tidur.ubah' || kunci === 'tempat_tidur.tutup'">
      <KolomIsian v-slot="a" label="Tempat tidur" :error="g()" petunjuk="Ketik kode TT, mis. A-1-101-1." wajib>
        <PemilihLokasi v-bind="a" v-model="lokasiId" :jenis="['tempat_tidur']" :label-terpilih="labelObjek" @pilih="pilihObjek" />
      </KolomIsian>
    </template>

    <template v-else-if="kunci === 'kamar.tambah'">
      <KolomIsian v-slot="a" label="Ruang" :error="g('lokasi_id')" petunjuk="Ruang aktif yang belum menjadi kamar." wajib>
        <PemilihLokasi v-bind="a" v-model="lokasiId" :jenis="['ruang']" :label-terpilih="labelLokasi" @pilih="pilihRuangKamar" />
      </KolomIsian>
    </template>

    <template v-else-if="kunci === 'kamar.ubah'">
      <KolomIsian v-slot="a" label="Kamar (ruang)" :error="g()" wajib>
        <PemilihLokasi v-bind="a" v-model="lokasiId" :jenis="['ruang']" :label-terpilih="labelObjek" @pilih="pilihObjek" />
      </KolomIsian>
    </template>

    <template v-else-if="kunci === 'ruang.tambah'">
      <KolomIsian v-slot="a" label="Induk (lantai/sayap)" :error="g('induk_id')" wajib>
        <PemilihLokasi v-bind="a" :model-value="(nilai.induk_id as string | null) ?? null" :jenis="['lantai', 'sayap']" :label-terpilih="labelLokasi" @update:model-value="(v: string | null) => set('induk_id', v)" />
      </KolomIsian>
      <div class="kisi">
        <KolomIsian v-slot="a" label="Kode" :error="g('kode')" wajib>
          <input v-bind="a" :value="nilai.kode ?? ''" class="isian kode" maxlength="10" autocomplete="off" @input="set('kode', ($event.target as HTMLInputElement).value.toUpperCase())" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Nama ruang" :error="g('nama')" wajib>
          <input v-bind="a" :value="nilai.nama ?? ''" class="isian" autocomplete="off" @input="set('nama', ($event.target as HTMLInputElement).value)" />
        </KolomIsian>
      </div>
    </template>

    <template v-else-if="kunci === 'ruang.ubah'">
      <KolomIsian v-slot="a" label="Ruang" :error="g()" wajib>
        <PemilihLokasi v-bind="a" v-model="lokasiId" :jenis="['ruang']" :label-terpilih="labelObjek" @pilih="pilihObjek" />
      </KolomIsian>
    </template>

    <p v-if="memuat" class="ket" role="status">Memuat nilai saat ini…</p>
    <p v-if="galatMuat" class="kolom-error" role="alert">{{ galatMuat }}</p>

    <!-- Nilai baru untuk ubah (dan isian tambahan) -->
    <div v-if="(aksi === 'ubah' && objekId) || kunci === 'tempat_tidur.tambah' || kunci === 'kamar.tambah' || kunci === 'ruang.tambah'" class="kisi">
      <template v-if="jenisObjek === 'tempat_tidur' && aksi === 'ubah'">
        <KolomIsian v-slot="a" label="Kelas rawat" :error="g('kelas_rawat_id')">
          <select v-bind="a" :value="nilai.kelas_rawat_id ?? ''" class="isian" @change="set('kelas_rawat_id', ($event.target as HTMLSelectElement).value)">
            <option v-for="k in kelas.data.value ?? []" :key="k.id" :value="k.id">{{ k.kode }} · {{ k.nama }}</option>
          </select>
        </KolomIsian>
      </template>
      <template v-if="jenisObjek === 'tempat_tidur' && aksi !== 'tutup'">
        <KolomIsian v-slot="a" label="Aturan jenis kelamin" :error="g('aturan_jenis_kelamin')">
          <select v-bind="a" :value="nilai.aturan_jenis_kelamin ?? 'campur'" class="isian" @change="set('aturan_jenis_kelamin', ($event.target as HTMLSelectElement).value)">
            <option v-for="(label, k) in LABEL_JENIS_KELAMIN" :key="k" :value="k">{{ label }}</option>
          </select>
        </KolomIsian>
        <label class="centang"><input type="checkbox" :checked="!!nilai.isolasi" @change="set('isolasi', ($event.target as HTMLInputElement).checked)" />Isolasi</label>
        <label class="centang"><input type="checkbox" :checked="!!nilai.ekstra" @change="set('ekstra', ($event.target as HTMLInputElement).checked)" />Ekstra (darurat lonjakan)</label>
      </template>
      <template v-if="jenisObjek === 'kamar'">
        <KolomIsian v-slot="a" label="Bangsal" :error="g('bangsal_id')" :wajib="aksi === 'tambah'">
          <select v-bind="a" :value="nilai.bangsal_id ?? ''" class="isian" @change="set('bangsal_id', ($event.target as HTMLSelectElement).value)">
            <option value="" disabled>Pilih</option>
            <option v-for="b in bangsal.data.value ?? []" :key="b.id" :value="b.id">{{ b.kode }} · {{ b.nama }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Kapasitas maksimum TT" :error="g('kapasitas_maksimum')" petunjuk="Kosong = tanpa batas.">
          <input v-bind="a" :value="nilai.kapasitas_maksimum ?? ''" class="isian" inputmode="numeric" maxlength="2" @input="set('kapasitas_maksimum', ($event.target as HTMLInputElement).value)" />
        </KolomIsian>
      </template>
      <template v-if="jenisObjek === 'ruang'">
        <KolomIsian v-if="aksi === 'ubah'" v-slot="a" label="Nama ruang" :error="g('nama')">
          <input v-bind="a" :value="nilai.nama ?? ''" class="isian" autocomplete="off" @input="set('nama', ($event.target as HTMLInputElement).value)" />
        </KolomIsian>
        <KolomIsian v-slot="a" label="Fungsi" :error="g('fungsi')" :wajib="aksi === 'tambah'">
          <select v-bind="a" :value="nilai.fungsi ?? ''" class="isian" @change="set('fungsi', ($event.target as HTMLSelectElement).value)">
            <option value="" disabled>Pilih</option>
            <option v-for="[f, label] in DAFTAR_FUNGSI" :key="f" :value="f">{{ label }}</option>
          </select>
        </KolomIsian>
        <KolomIsian v-slot="a" label="Unit pengelola" :error="g('unit_organisasi_id')">
          <PemilihUnit v-bind="a" :model-value="(nilai.unit_organisasi_id as string | null) ?? null" :label-terpilih="labelUnit" opsi-kosong="Ikuti pengelola induk" @update:model-value="(v: string | null) => set('unit_organisasi_id', v)" />
        </KolomIsian>
        <KolomIsian v-if="aksi === 'ubah'" v-slot="a" label="Status" :error="g('status')" petunjuk="Berlaku pada tanggal efektif usulan.">
          <select v-bind="a" :value="nilai.status ?? 'aktif'" class="isian" @change="set('status', ($event.target as HTMLSelectElement).value)">
            <option v-for="(label, s) in LABEL_STATUS_LOKASI" :key="s" :value="s">{{ label }}</option>
          </select>
        </KolomIsian>
      </template>
    </div>
    <KolomIsian v-if="aksi === 'tutup' && objekId" v-slot="a" label="Alasan penutupan" :error="g('alasan')" petunjuk="TT tidak dihitung mulai tanggal efektif, permanen." wajib>
      <input v-bind="a" :value="nilai.alasan ?? ''" class="isian" maxlength="200" @input="set('alasan', ($event.target as HTMLInputElement).value)" />
    </KolomIsian>
  </fieldset>
</template>

<style scoped>
.butir {
  margin: 0 0 var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
legend {
  padding: 0 var(--space-1);
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
}
.baris-atas {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: var(--space-2);
}
.hapus {
  margin-top: 22px;
}
.kisi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.centang {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
  font-size: 13px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .centang {
    min-height: 44px;
  }
}
.ket {
  margin: 0 0 var(--space-2);
  color: var(--muted);
  font-size: 12.5px;
}
</style>
