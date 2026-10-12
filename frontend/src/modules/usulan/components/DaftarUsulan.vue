<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggal } from '@/shared/utils/waktu'
import { daftarUsulan } from '../api'
import { KUNCI_USULAN, LABEL_JENIS_DATA, LABEL_TAHAP, PIL_TAHAP } from '../usulan'
import type { FilterTahap, UsulanDataInduk } from '../tipe'

/**
 * Daftar usulan perubahan data induk (tiket 1.8c fe 1): kartu ringkasan per tahap (`meta.jumlah` dari server; gagal =
 * 30 hari terakhir), filter Terbuka / Perlu saya / Semua, urut waktu pengajuan pertama. Tahap final (berlaku, gagal)
 * hanya di "Semua".
 */
const terpilih = defineModel<string | null>({ required: true })
const emit = defineEmits<{ ajukan: [] }>()

const filter = ref<'terbuka' | 'perlu_saya' | 'semua'>('terbuka')
const parameter = computed<{ tahap?: FilterTahap; perlu_saya?: boolean }>(() =>
  filter.value === 'terbuka' ? { tahap: 'terbuka' } : filter.value === 'perlu_saya' ? { tahap: 'terbuka', perlu_saya: true } : {},
)
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_USULAN.daftar(parameter.value)),
  queryFn: ({ pageParam }) => daftarUsulan(parameter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const jumlah = computed(() => kueri.data.value?.pages[0]?.meta.jumlah)
const KARTU = [
  { kunci: 'diusulkan', label: 'Menunggu verifikasi', ket: 'Oleh pemverifikasi data' },
  { kunci: 'diverifikasi', label: 'Menunggu persetujuan', ket: 'Oleh pejabat penyetuju' },
  { kunci: 'disetujui', label: 'Terjadwal berlaku', ket: 'Berlaku otomatis pada tanggalnya' },
  { kunci: 'dikembalikan', label: 'Dikembalikan', ket: 'Perlu diperbaiki pengusul' },
  { kunci: 'gagal_diterapkan', label: 'Gagal diterapkan', ket: '30 hari terakhir' },
] as const
const KOLOM: KolomTabel[] = [
  { kunci: 'nomor', label: 'Usulan', lebar: '170px' },
  { kunci: 'ringkasan', label: 'Perubahan' },
  { kunci: 'berlaku', label: 'Berlaku', prioritas: 2, lebar: '130px' },
  { kunci: 'tahap', label: 'Tahap', lebar: '170px' },
]
</script>

<template>
  <div class="daftar-usulan">
    <section class="kpi" aria-label="Ringkasan usulan">
      <div v-for="k in KARTU" :key="k.kunci" class="kartu kartu-kpi" :class="{ sorot: (k.kunci === 'dikembalikan' || k.kunci === 'gagal_diterapkan') && (jumlah?.[k.kunci] ?? 0) > 0 }">
        <span class="label">{{ k.label }}</span>
        <b class="angka">{{ jumlah ? jumlah[k.kunci] : '—' }}</b>
        <span class="ket">{{ k.ket }}</span>
      </div>
    </section>

    <section class="kartu" aria-labelledby="judul-daftar-usulan">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-daftar-usulan">Usulan perubahan data induk</h2>
          <p>Pengusul → pemverifikasi → penyetuju → berlaku pada tanggalnya, berversi.</p>
        </div>
        <button v-can="'master_data.usulan.input'" type="button" class="tombol" @click="emit('ajukan')"><Ikon nama="tambah" :ukuran="16" />Ajukan usulan</button>
      </div>
      <div class="filter" role="radiogroup" aria-label="Saring usulan">
        <label v-for="[nilai, label] in [['terbuka', 'Terbuka'], ['perlu_saya', 'Perlu saya'], ['semua', 'Semua']] as const" :key="nilai" class="pilih" :class="{ aktif: filter === nilai }">
          <input v-model="filter" type="radio" name="filter-usulan" :value="nilai" />
          {{ label }}
        </label>
      </div>
      <DataTable
        :kolom="KOLOM"
        :baris="baris"
        :kunci-baris="(u: UsulanDataInduk) => u.id"
        label="Daftar usulan"
        dapat-dipilih
        :terpilih="terpilih"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        :teks-kosong="filter === 'perlu_saya' ? 'Tidak ada usulan yang menunggu tindakan Anda.' : 'Belum ada usulan.'"
        @pilih="(u: UsulanDataInduk) => (terpilih = u.id)"
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-nomor="{ baris: u }">
          <code>{{ u.nomor }}</code>
          <span class="ket">{{ LABEL_JENIS_DATA[u.jenis_data] ?? u.jenis_data }}<template v-if="u.putaran > 1"> · putaran {{ u.putaran }}</template></span>
        </template>
        <template #sel-ringkasan="{ baris: u }">
          <span class="ringkasan">{{ u.ringkasan }}</span>
          <span v-if="u.perubahan.some((b: UsulanDataInduk['perubahan'][number]) => b.objek_berubah)" class="ket peringatan"><Ikon nama="peringatan" :ukuran="12" />Objek berubah sejak diusulkan</span>
        </template>
        <template #sel-berlaku="{ baris: u }">{{ formatTanggal(`${u.berlaku_dari}T00:00:00`) }}</template>
        <template #sel-tahap="{ baris: u }">
          <span class="pil" :class="PIL_TAHAP[u.tahap as UsulanDataInduk['tahap']]">{{ LABEL_TAHAP[u.tahap as UsulanDataInduk['tahap']] }}</span>
        </template>
      </DataTable>
    </section>
  </div>
</template>

<style scoped>
.daftar-usulan {
  display: grid;
  gap: var(--space-4);
  min-width: 0;
}
.kpi {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: var(--space-3);
}
.kartu-kpi {
  display: grid;
  gap: 2px;
  padding: var(--space-3) var(--space-4);
}
.kartu-kpi.sorot {
  box-shadow: inset 3px 0 0 var(--danger);
}
.label {
  color: var(--muted);
  font-size: 12.5px;
}
.angka {
  font-size: 22px;
  font-weight: var(--font-weight-semibold);
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.peringatan {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--warn);
}
.filter {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.pilih {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 0 var(--space-3);
  border: 1px solid var(--border-input);
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
}
.pilih.aktif {
  border-color: var(--brand-600);
  background: var(--brand-25);
}
.pilih input {
  margin: 0;
}
.pilih:focus-within {
  box-shadow: var(--ring);
}
@media (min-width: 768px) and (max-width: 1279.98px) {
  .pilih {
    min-height: 44px;
  }
}
code {
  font-family: var(--font-mono);
}
</style>
