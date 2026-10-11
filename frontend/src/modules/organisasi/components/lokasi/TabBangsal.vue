<script setup lang="ts">
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import DataTable, { type KolomTabel } from '@/design-system/components/DataTable.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { refDebounced } from '@/shared/composables/refDebounced'
import type { Berversi } from '../../api'
import { ambilBangsal, daftarBangsal, daftarKamar } from '../../apiLokasi'
import { KUNCI_LOKASI } from '../../lokasi'
import type { Bangsal } from '../../tipeLokasi'
import DialogStatus from '../DialogStatus.vue'
import DialogBangsal from './DialogBangsal.vue'

/**
 * Bangsal dan ringkasan TT per kelas hari ini (tiket 1.8b fe 2): dihitung (tersedia + ekstra), akan ditutup (bagian
 * dari yang dihitung), belum berlaku, dan ditutup kumulatif. Kamar yang TT-nya melebihi kapasitas maksimum ditampilkan
 * sebagai peringatan di atas daftar; memilihnya membuka ruang itu di tab Lokasi.
 */
const qc = useQueryClient()
const router = useRouter()
const cari = ref('')
const cariTunda = refDebounced(cari, 300)
const status = ref<'aktif' | 'nonaktif' | ''>('aktif')
const filter = computed(() => ({ cari: cariTunda.value.trim() || undefined, status: status.value || undefined }))

const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_LOKASI.daftarBangsal(filter.value)),
  queryFn: ({ pageParam }) => daftarBangsal(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
const melebihi = useQuery({ queryKey: KUNCI_LOKASI.kamarMelebihi, queryFn: async () => (await daftarKamar({ melebihi_kapasitas: true }, null, 50)).data })

const total = (b: Bangsal) =>
  b.ringkasan_tt.reduce((n, r) => ({ dihitung: n.dihitung + r.jumlah_tersedia + r.jumlah_ekstra, ekstra: n.ekstra + r.jumlah_ekstra, ditutup: n.ditutup + r.ditutup }), { dihitung: 0, ekstra: 0, ditutup: 0 })
const kolom = computed<KolomTabel[]>(() => [
  { kunci: 'kode', label: 'Bangsal', lebar: '220px' },
  { kunci: 'ringkasan', label: 'TT per kelas hari ini' },
  { kunci: 'unit', label: 'Unit pengelola', prioritas: 3 },
  { kunci: 'status', label: 'Status', prioritas: 2, lebar: '100px' },
  ...(boleh('master_data.organisasi.perbaiki') ? [{ kunci: 'aksi', label: 'Aksi', rata: 'kanan' as const, lebar: '200px' }] : []),
])

const dialogBangsal = ref(false)
const dialogStatus = ref(false)
const target = ref<Berversi<Bangsal> | null>(null)
const memuatId = ref<string | null>(null)
function tambah() {
  target.value = null
  dialogBangsal.value = true
}
async function buka(b: Bangsal, aksi: 'ubah' | 'status') {
  memuatId.value = b.id
  try {
    target.value = await ambilBangsal(b.id)
    if (aksi === 'ubah') dialogBangsal.value = true
    else dialogStatus.value = true
  } catch (e) {
    tampilkanToast(pesanError(e), 'error')
  } finally {
    memuatId.value = null
  }
}
function selesai() {
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.bangsal })
}
function bukaRuang(lokasiId: string) {
  void router.replace({ query: { tab: 'lokasi', lokasi: lokasiId } })
}
</script>

<template>
  <div class="tab-bangsal">
    <section v-if="melebihi.data.value?.length" class="catatan catatan-peringatan melebihi" aria-labelledby="judul-melebihi">
      <Ikon nama="peringatan" :ukuran="16" />
      <div>
        <h2 id="judul-melebihi">{{ melebihi.data.value.length }} kamar melebihi kapasitas maksimum</h2>
        <ul>
          <li v-for="k in melebihi.data.value" :key="k.id">
            <button type="button" class="tombol-tautan" @click="bukaRuang(k.lokasi.id)">{{ k.lokasi.kode_lengkap }} · {{ k.lokasi.nama }}</button>
            — {{ k.jumlah_tempat_tidur_aktif }} TT dihitung, kapasitas {{ k.kapasitas_maksimum }} ({{ k.bangsal.nama }})
          </li>
        </ul>
      </div>
    </section>

    <section class="kartu" aria-labelledby="judul-bangsal">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-bangsal">Bangsal</h2>
          <p>Dihitung = TT yang masuk kapasitas hari ini (tersedia + ekstra). Ditutup dihitung kumulatif.</p>
        </div>
        <button v-can="'master_data.organisasi.input'" type="button" class="tombol" @click="tambah"><Ikon nama="tambah" :ukuran="16" />Tambah bangsal</button>
      </div>
      <div class="filter">
        <label class="sr-only" for="cari-bangsal">Cari bangsal</label>
        <input id="cari-bangsal" v-model="cari" type="search" class="isian" placeholder="Cari kode atau nama" />
        <label class="sr-only" for="status-bangsal">Status bangsal</label>
        <select id="status-bangsal" v-model="status" class="isian">
          <option value="aktif">Aktif</option>
          <option value="nonaktif">Nonaktif</option>
          <option value="">Semua status</option>
        </select>
      </div>
      <DataTable
        :kolom="kolom"
        :baris="baris"
        :kunci-baris="(b: Bangsal) => b.id"
        label="Daftar bangsal"
        :memuat="kueri.isPending.value"
        :memuat-lagi="kueri.isFetchingNextPage.value"
        :error="kueri.isError.value ? pesanError(kueri.error.value) : null"
        :ada-lagi="kueri.hasNextPage.value"
        teks-kosong="Belum ada bangsal yang cocok."
        @muat-lagi="kueri.fetchNextPage()"
        @coba-lagi="kueri.refetch()"
      >
        <template #sel-kode="{ baris: b }">
          <b>{{ b.nama }}</b>
          <span class="ket"><code>{{ b.kode }}</code> · {{ b.jumlah_kamar }} kamar</span>
        </template>
        <template #sel-ringkasan="{ baris: b }">
          <span v-if="!b.ringkasan_tt.length" class="ket">Belum ada TT</span>
          <template v-else>
            <ul class="ringkasan">
              <li v-for="r in b.ringkasan_tt" :key="r.kelas_rawat.id">
                <b>{{ r.kelas_rawat.nama }}</b>: {{ r.jumlah_tersedia + r.jumlah_ekstra }} dihitung<template v-if="r.jumlah_ekstra"> ({{ r.jumlah_ekstra }} ekstra)</template
                ><template v-if="r.akan_ditutup"> · {{ r.akan_ditutup }} akan ditutup</template><template v-if="r.belum_berlaku"> · {{ r.belum_berlaku }} belum berlaku</template
                ><template v-if="r.ditutup"> · {{ r.ditutup }} ditutup</template>
              </li>
            </ul>
            <span class="ket">Total {{ total(b).dihitung }} dihitung<template v-if="total(b).ekstra">, {{ total(b).ekstra }} ekstra</template><template v-if="total(b).ditutup">, {{ total(b).ditutup }} ditutup</template></span>
          </template>
        </template>
        <template #sel-unit="{ baris: b }">{{ b.unit.nama }}</template>
        <template #sel-status="{ baris: b }">
          <span class="pil" :class="b.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ b.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
        </template>
        <template #sel-aksi="{ baris: b }">
          <span class="aksi">
            <Tombol :sibuk="memuatId === b.id" :aria-label="`Ubah ${b.nama}`" @click="buka(b, 'ubah')">Ubah</Tombol>
            <Tombol :sibuk="memuatId === b.id" :aria-label="`${b.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan'} ${b.nama}`" @click="buka(b, 'status')">
              {{ b.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}
            </Tombol>
          </span>
        </template>
      </DataTable>
    </section>

    <DialogBangsal v-model:open="dialogBangsal" :bangsal="target" @selesai="selesai" @muat-ulang="selesai" />
    <DialogStatus v-model:open="dialogStatus" jenis="bangsal" :target="target" @selesai="selesai" @muat-ulang="selesai" />
  </div>
</template>

<style scoped>
.tab-bangsal {
  display: grid;
  gap: var(--space-4);
}
.melebihi {
  margin: 0;
}
.melebihi h2 {
  margin: 0 0 var(--space-1);
  font-size: 13.5px;
}
.melebihi ul {
  margin: 0;
  padding-left: 18px;
}
.filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 160px;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}
.ringkasan {
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 13px;
}
.ket {
  display: block;
  color: var(--muted);
  font-size: 12px;
}
.aksi {
  display: inline-flex;
  gap: var(--space-2);
}
@media (max-width: 599.98px) {
  .filter {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
