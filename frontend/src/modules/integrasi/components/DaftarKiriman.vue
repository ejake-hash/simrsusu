<script setup lang="ts">
import { useInfiniteQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { daftarKiriman, kirimUlang } from '../api'
import { KUNCI_INTEGRASI, LABEL_LINGKUNGAN, LABEL_SISTEM, LABEL_STATUS_KIRIMAN, PIL_STATUS_KIRIMAN } from '../integrasi'
import type { FilterKiriman, Kiriman, LingkunganIntegrasi, StatusKiriman, StatusSistemIntegrasi, SistemIntegrasi } from '../tipe'

/**
 * Kotak keluar (GET /integrasi/kotak-keluar, kontrak v0.13.0): kiriman ke sistem luar per tujuan yang diizinkan, filter
 * tujuan/status/lingkungan/tanggal, terbaru dulu (kursor). Muatan kiriman tidak pernah dikirim server; yang tampil
 * resource, objek, status, penyebab tertahan dan unit pemilik datanya. Kirim ulang mengikuti `dapat_kirim_ulang`.
 */
const props = defineProps<{ tujuan: SistemIntegrasi[]; sistem: StatusSistemIntegrasi[] }>()
const qc = useQueryClient()

const tujuan = ref<SistemIntegrasi | ''>('')
const status = ref<StatusKiriman | ''>('')
const lingkungan = ref<LingkunganIntegrasi | ''>('')
const dari = ref('')
const sampai = ref('')
const salahTanggal = computed(() => (dari.value && sampai.value && dari.value > sampai.value ? 'Tidak boleh sebelum tanggal awal.' : undefined))
const filter = computed<FilterKiriman>(() => ({
  tujuan: tujuan.value || (props.tujuan.length === 1 ? props.tujuan[0] : undefined),
  status: status.value || undefined,
  lingkungan: lingkungan.value || undefined,
  dari: dari.value || undefined,
  sampai: sampai.value || undefined,
}))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_INTEGRASI.kiriman(filter.value)),
  queryFn: ({ pageParam }) => daftarKiriman(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => !salahTanggal.value),
})
const baris = computed(() => (kueri.data.value?.pages.flatMap((h) => h.data) ?? []).filter((k) => props.tujuan.includes(k.tujuan)))
const KOLOM: KolomTabel[] = [
  { kunci: 'dibuat', label: 'Dibuat', lebar: '150px' },
  { kunci: 'resource', label: 'Kiriman' },
  { kunci: 'status', label: 'Status' },
  { kunci: 'percobaan', label: 'Percobaan', prioritas: 2, rata: 'kanan', lebar: '96px' },
  { kunci: 'aksi', label: 'Aksi', lebar: '130px' },
]

const pilihan = ref<Kiriman | null>(null)
const bukaKirim = ref(false)
const sibuk = ref(false)
const lingkunganAktif = (t: SistemIntegrasi) => props.sistem.find((x) => x.kode === t)?.lingkungan_aktif ?? null
function mulaiKirim(k: Kiriman) {
  pilihan.value = k
  bukaKirim.value = true
}
async function kirim() {
  if (!pilihan.value) return
  sibuk.value = true
  try {
    const k = await kirimUlang(pilihan.value.id)
    bukaKirim.value = false
    tampilkanToast(`Kiriman ${k.jenis_resource} diantrekan ulang ke ${LABEL_SISTEM[k.tujuan]}.`)
    void qc.invalidateQueries({ queryKey: KUNCI_INTEGRASI.semua })
  } catch (err) {
    tampilkanToast(pesanError(err), 'error')
    void kueri.refetch()
  } finally {
    sibuk.value = false
  }
}
</script>

<template>
  <section class="kartu" aria-labelledby="judul-kotak-keluar">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-kotak-keluar">Kotak keluar</h2>
        <p>Kiriman ke sistem luar; yang gagal atau tertahan bisa dikirim ulang tanpa duplikat di sistem tujuan.</p>
      </div>
    </div>
    <div class="filter">
      <KolomIsian v-if="props.tujuan.length > 1" v-slot="a" label="Tujuan">
        <select v-bind="a" v-model="tujuan" class="isian">
          <option value="">Semua</option>
          <option v-for="t in props.tujuan" :key="t" :value="t">{{ LABEL_SISTEM[t] }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Status">
        <select v-bind="a" v-model="status" class="isian">
          <option value="">Semua</option>
          <option v-for="(l, k) in LABEL_STATUS_KIRIMAN" :key="k" :value="k">{{ l }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Lingkungan">
        <select v-bind="a" v-model="lingkungan" class="isian">
          <option value="">Semua</option>
          <option v-for="(l, k) in LABEL_LINGKUNGAN" :key="k" :value="k">{{ l }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dari tanggal">
        <input v-bind="a" v-model="dari" type="date" class="isian" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Sampai tanggal" :error="salahTanggal">
        <input v-bind="a" v-model="sampai" type="date" class="isian" />
      </KolomIsian>
    </div>
    <DataTable
      :kolom="KOLOM"
      :baris="baris"
      :kunci-baris="(k: Kiriman) => k.id"
      label="Daftar kiriman"
      :memuat="kueri.isPending.value && !salahTanggal"
      :memuat-lagi="kueri.isFetchingNextPage.value"
      :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
      :ada-lagi="kueri.hasNextPage.value"
      teks-kosong="Tidak ada kiriman yang cocok."
      @muat-lagi="kueri.fetchNextPage()"
      @coba-lagi="kueri.refetch()"
    >
      <template #sel-dibuat="{ baris: k }">{{ formatTanggalWaktu(k.dibuat_pada) }}</template>
      <template #sel-resource="{ baris: k }">
        <b>{{ k.jenis_resource }}</b> · {{ LABEL_SISTEM[k.tujuan as SistemIntegrasi] }}
        <span class="ket">{{ k.objek_jenis }} · {{ k.lingkungan ? LABEL_LINGKUNGAN[k.lingkungan as LingkunganIntegrasi] : 'tanpa lingkungan (dibuat saat integrasi nonaktif)' }}<template v-if="k.id_luar"> · ID luar <code>{{ k.id_luar }}</code></template></span>
      </template>
      <template #sel-status="{ baris: k }">
        <span class="pil" :class="PIL_STATUS_KIRIMAN[k.status as StatusKiriman]">{{ LABEL_STATUS_KIRIMAN[k.status as StatusKiriman] }}</span>
        <span v-if="k.penyebab_tertahan" class="ket">{{ k.penyebab_tertahan.pesan }}<template v-if="k.pemilik_unit"> · perbaiki oleh {{ k.pemilik_unit.kode }} {{ k.pemilik_unit.nama }}</template></span>
        <span v-else-if="k.error_terakhir" class="ket">{{ k.error_terakhir }}</span>
        <span v-else-if="k.status === 'menunggu' && k.coba_lagi_pada" class="ket">Dicoba {{ formatTanggalWaktu(k.coba_lagi_pada) }}</span>
        <span v-else-if="k.terkirim_pada" class="ket">{{ formatTanggalWaktu(k.terkirim_pada) }}</span>
      </template>
      <template #sel-percobaan="{ baris: k }">{{ k.percobaan }}</template>
      <template #sel-aksi="{ baris: k }">
        <button v-if="k.dapat_kirim_ulang" type="button" class="tombol" @click="mulaiKirim(k as Kiriman)">Kirim ulang</button>
      </template>
    </DataTable>

    <Dialog v-model:open="bukaKirim" judul="Kirim ulang kiriman ini?" :sibuk="sibuk">
      <p v-if="pilihan">
        {{ pilihan.jenis_resource }} ke {{ LABEL_SISTEM[pilihan.tujuan] }}
        <template v-if="pilihan.lingkungan">{{ LABEL_LINGKUNGAN[pilihan.lingkungan].toLowerCase() }}</template>
        diantrekan lagi; sistem tujuan tidak menerima duplikat.
      </p>
      <p v-if="pilihan && !pilihan.lingkungan && lingkunganAktif(pilihan.tujuan)" class="catatan catatan-peringatan">
        Kiriman ini dibuat saat integrasi nonaktif dan akan diikat ke lingkungan aktif:
        <b>{{ LABEL_LINGKUNGAN[lingkunganAktif(pilihan.tujuan)!] }}</b>.
      </p>
      <template #aksi>
        <button type="button" class="tombol" :disabled="sibuk" @click="bukaKirim = false">Batal</button>
        <Tombol varian="utama" :sibuk="sibuk" @click="kirim">Kirim ulang</Tombol>
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.kartu-kepala h2 {
  margin: 0;
  font-size: 15px;
}
.kartu-kepala p {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
.filter {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  align-items: start;
  gap: 0 var(--space-3);
  padding: var(--space-3) var(--space-4) 0;
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
  overflow-wrap: anywhere;
}
code {
  font-family: var(--font-mono);
}
</style>
