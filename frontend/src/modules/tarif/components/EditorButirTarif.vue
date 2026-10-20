<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import type { AksiUsulan, ButirUsulan, IsianButir } from '@/modules/usulan/tipe'
import { daftarTarif } from '../api'
import { periksaHarga } from '../tarif'
import type { IsianHargaTarif, Tarif } from '../tipe'
import EditorHarga from './EditorHarga.vue'

/**
 * Satu butir usulan tarif (kontrak v0.18.0): tambah (kode, nama, kelompok, harga), ubah (hanya isian yang berbeda;
 * harga mengganti seluruh daftar per kelas) atau tutup (alasan). `bentuk()` mengembalikan isian butir atau pesan galat
 * klien; galat server per butir lewat `galat` (`perubahan.<i>[.<isian>]`).
 */
const props = defineProps<{ nomor: number; awal?: ButirUsulan | null; tarifAwal?: Tarif | null; galat?: Record<string, string> }>()
const emit = defineEmits<{ hapus: [] }>()

const aksi = ref<AksiUsulan | ''>('')
const objek = ref<Tarif | null>(null)
const objekId = ref<string | null>(null)
const labelObjek = ref<string | null>(null)
const cari = ref('')
const kode = ref('')
const nama = ref('')
const kelompok = ref('')
const status = ref<'aktif' | 'nonaktif'>('aktif')
const harga = ref<IsianHargaTarif[]>([])
const ubahHarga = ref(false)
const alasan = ref('')

const perluObjek = computed(() => aksi.value === 'ubah' || aksi.value === 'tutup')
const hasil = useQuery({
  queryKey: computed(() => ['tarif', 'pilih', cari.value.trim()]),
  queryFn: async () => (await daftarTarif({ cari: cari.value.trim() || undefined, status: 'aktif' }, null, 20)).data,
  enabled: computed(() => perluObjek.value && !objek.value),
  staleTime: 10_000,
})
const komponen = (t: Tarif): IsianHargaTarif[] => t.harga.map(({ kelas_rawat_id, jasa_sarana, jasa_pelayanan, bhp }) => ({ kelas_rawat_id, jasa_sarana, jasa_pelayanan, bhp }))

function pilih(t: Tarif | null) {
  objek.value = t
  objekId.value = t?.id ?? null
  labelObjek.value = t ? `${t.kode} · ${t.nama}` : null
  if (!t) return
  nama.value = t.nama
  kelompok.value = t.kelompok
  status.value = t.status
  harga.value = komponen(t)
  ubahHarga.value = false
}
function gantiAksi() {
  pilih(null)
  cari.value = ''
  kode.value = nama.value = kelompok.value = alasan.value = ''
  status.value = 'aktif'
  harga.value = aksi.value === 'tambah' ? [{ kelas_rawat_id: '', jasa_sarana: '', jasa_pelayanan: '', bhp: '' }] : []
}

watch(
  () => [props.awal, props.tarifAwal] as const,
  async ([b, t]) => {
    if (t) {
      aksi.value = 'ubah'
      pilih(t)
      return
    }
    if (!b) return
    aksi.value = b.aksi
    const n = b.nilai_baru as Record<string, unknown>
    if (b.aksi === 'tambah') {
      kode.value = String(n.kode ?? '')
      nama.value = String(n.nama ?? '')
      kelompok.value = String(n.kelompok ?? '')
      harga.value = ((n.harga as IsianHargaTarif[] | undefined) ?? []).map((h) => ({ ...h }))
      return
    }
    objekId.value = b.objek_id
    labelObjek.value = b.objek_label
    if (b.aksi === 'tutup') alasan.value = String(n.alasan ?? '')
    // Nilai saat ini dimuat ulang agar isian yang tidak diubah ikut tampil; kode dari label "KODE · nama".
    const kd = b.objek_label.split(' · ')[0] ?? ''
    const ada = (await daftarTarif({ cari: kd }, null, 20)).data.find((x) => x.id === b.objek_id)
    if (ada) pilih(ada)
    if (b.aksi === 'ubah') {
      if (typeof n.nama === 'string') nama.value = n.nama
      if (typeof n.kelompok === 'string') kelompok.value = n.kelompok
      if (n.status === 'aktif' || n.status === 'nonaktif') status.value = n.status
      if (Array.isArray(n.harga)) {
        harga.value = (n.harga as IsianHargaTarif[]).map((h) => ({ ...h }))
        ubahHarga.value = true
      }
    }
  },
  { immediate: true },
)

const g = (isian?: string) => props.galat?.[isian ? `perubahan.${props.nomor}.${isian}` : `perubahan.${props.nomor}`]
const salinHarga = () => harga.value.map((h) => ({ kelas_rawat_id: h.kelas_rawat_id, jasa_sarana: h.jasa_sarana.trim(), jasa_pelayanan: h.jasa_pelayanan.trim(), bhp: h.bhp.trim() }))

/** Isian butir siap kirim, atau pesan galat klien. */
function bentuk(): IsianButir | string {
  if (!aksi.value) return 'Pilih jenis perubahan.'
  if (aksi.value === 'tambah') {
    const kd = kode.value.trim().toUpperCase()
    if (!/^[A-Z0-9-]{1,20}$/.test(kd)) return 'Kode tarif 1–20 huruf besar, angka, atau tanda hubung.'
    if (nama.value.trim().length < 2) return 'Nama tarif minimal 2 karakter.'
    if (!harga.value.length) return 'Isi harga minimal satu kelas rawat.'
    const salah = periksaHarga(salinHarga())
    if (salah) return salah
    return { objek_jenis: 'tarif', aksi: 'tambah', objek_id: null, nilai_baru: { kode: kd, nama: nama.value.trim(), kelompok: kelompok.value.trim(), harga: salinHarga() } }
  }
  if (!objekId.value) return 'Pilih tarif yang diubah.'
  if (aksi.value === 'tutup') {
    if (alasan.value.trim().length < 5) return 'Isi alasan penutupan, minimal 5 karakter.'
    return { objek_jenis: 'tarif', aksi: 'tutup', objek_id: objekId.value, nilai_baru: { alasan: alasan.value.trim() } }
  }
  const beda: Record<string, unknown> = {}
  if (nama.value.trim() !== (objek.value?.nama ?? '')) {
    if (nama.value.trim().length < 2) return 'Nama tarif minimal 2 karakter.'
    beda.nama = nama.value.trim()
  }
  if (kelompok.value.trim() !== (objek.value?.kelompok ?? '')) beda.kelompok = kelompok.value.trim()
  if (objek.value && status.value !== objek.value.status) beda.status = status.value
  if (ubahHarga.value) {
    if (!harga.value.length) return 'Isi harga minimal satu kelas rawat.'
    const salah = periksaHarga(salinHarga())
    if (salah) return salah
    beda.harga = salinHarga()
  }
  if (!Object.keys(beda).length) return 'Belum ada nilai yang diubah.'
  return { objek_jenis: 'tarif', aksi: 'ubah', objek_id: objekId.value, nilai_baru: beda }
}
defineExpose({ bentuk })
</script>

<template>
  <fieldset class="butir">
    <legend>Perubahan {{ nomor + 1 }}</legend>
    <div class="baris-atas">
      <KolomIsian v-slot="a" label="Jenis perubahan" :error="g()" wajib>
        <select v-bind="a" v-model="aksi" class="isian" @change="gantiAksi">
          <option value="" disabled>Pilih</option>
          <option value="tambah">Tambah tarif</option>
          <option value="ubah">Ubah tarif</option>
          <option value="tutup">Tutup tarif</option>
        </select>
      </KolomIsian>
      <button type="button" class="tombol hapus" :aria-label="`Hapus perubahan ${nomor + 1}`" @click="emit('hapus')">Hapus</button>
    </div>

    <template v-if="perluObjek">
      <p v-if="objek || labelObjek" class="terpilih">
        <b>{{ labelObjek }}</b>
        <button v-if="!awal" type="button" class="tautan" @click="pilih(null)">Ganti tarif</button>
      </p>
      <template v-else>
        <KolomIsian v-slot="a" label="Cari tarif" petunjuk="Ketik kode atau nama, lalu pilih dari daftar." wajib>
          <input v-bind="a" v-model="cari" type="search" class="isian" autocomplete="off" />
        </KolomIsian>
        <ul class="hasil" aria-label="Hasil pencarian tarif">
          <li v-if="hasil.isPending.value" class="ket" role="status">Mencari…</li>
          <li v-else-if="!hasil.data.value?.length" class="ket">Tidak ada tarif aktif yang cocok.</li>
          <li v-for="t in hasil.data.value ?? []" :key="t.id">
            <button type="button" class="pilihan" @click="pilih(t)">{{ t.kode }} · {{ t.nama }}</button>
          </li>
        </ul>
      </template>
    </template>

    <div v-if="aksi === 'tambah'" class="kisi">
      <KolomIsian v-slot="a" label="Kode tarif" :error="g('kode')" wajib>
        <input v-bind="a" v-model="kode" class="isian kode" maxlength="20" autocomplete="off" @input="kode = kode.toUpperCase()" />
      </KolomIsian>
    </div>
    <div v-if="aksi === 'tambah' || (aksi === 'ubah' && objekId)" class="kisi">
      <KolomIsian v-slot="a" label="Nama tarif" :error="g('nama')" wajib>
        <input v-bind="a" v-model="nama" class="isian" maxlength="200" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Kelompok" :error="g('kelompok')" petunjuk="Mis. jenis layanan.">
        <input v-bind="a" v-model="kelompok" class="isian" maxlength="50" autocomplete="off" />
      </KolomIsian>
      <KolomIsian v-if="aksi === 'ubah'" v-slot="a" label="Status" :error="g('status')">
        <select v-bind="a" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
        </select>
      </KolomIsian>
    </div>
    <label v-if="aksi === 'ubah' && objekId" class="centang"><input v-model="ubahHarga" type="checkbox" />Ubah harga per kelas (mengganti seluruh daftar harga)</label>
    <EditorHarga v-if="aksi === 'tambah' || (aksi === 'ubah' && ubahHarga)" v-model="harga" :galat="g('harga')" />
    <KolomIsian v-if="aksi === 'tutup' && objekId" v-slot="a" label="Alasan penutupan" :error="g('alasan')" petunjuk="Tarif tidak berlaku mulai tanggal efektif, permanen. Riwayat harga tetap terbaca." wajib>
      <input v-bind="a" v-model="alasan" class="isian" maxlength="200" />
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
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
}
.kode {
  font-family: var(--font-mono);
}
.terpilih {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
  font-size: 13px;
}
.tautan {
  padding: 0;
  border: 0;
  background: none;
  color: var(--primary);
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
}
.hasil {
  margin: 0 0 var(--space-3);
  padding: 0;
  list-style: none;
}
.pilihan {
  display: block;
  width: 100%;
  min-height: 32px;
  padding: var(--space-1) var(--space-2);
  border: 0;
  background: none;
  text-align: left;
  cursor: pointer;
  font: inherit;
}
.pilihan:hover {
  background: var(--surface-2, var(--border));
}
.centang {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 32px;
  margin-bottom: var(--space-3);
  font-size: 13px;
}
.ket {
  margin: 0 0 var(--space-2);
  color: var(--muted);
  font-size: 12.5px;
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .centang,
  .pilihan {
    min-height: 44px;
  }
}
</style>
