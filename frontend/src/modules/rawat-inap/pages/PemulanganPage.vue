<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import KeadaanKosong from '@/design-system/components/KeadaanKosong.vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import HalamanModul from '@/layouts/HalamanModul.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal } from '@/shared/utils/waktu'
import { formatNoRm } from '@/modules/pendaftaran/pasien'
import { bacaPetaTempatTidur, bacaRencanaPulang, bacaResumeMedis } from '../api'
import DialogPulangkan from '../components/DialogPulangkan.vue'
import PanelRencanaPulang from '../components/PanelRencanaPulang.vue'
import PanelResumeMedis from '../components/PanelResumeMedis.vue'
import { KUNCI_PETA, tandaStatus, teksHariRawat } from '../peta'
import { IZIN_PEMULANGAN, KUNCI_PEMULANGAN, LABEL_STATUS_KUNJUNGAN, LABEL_TUJUAN, rutePemulangan } from '../pemulangan'
import type { RawatInap, TempatTidurPeta, TujuanPulang } from '../tipe'

/**
 * Rawat Inap → Pemulangan dan → Rencana pulang (tiket 2.40, kontrak v0.39.0; FR-05-01..05). Satu layar untuk dua sub-menu:
 * gerbang halaman = izin lihat sub-menu rutenya, bagian lain tampil menurut izinnya sendiri (rencana pulang non-klinis,
 * resume dan pulang klinis). Tanpa `?kunjungan=`: daftar pasien dirawat dari peta TT (TT yang membawa id kunjungan).
 * Identitas dua penanda dari rencana pulang, resume, atau peta TT (yang pertama tersedia). Tautan tugas
 * RESUME_BELUM_TTE_48J memakai `/rawat-inap/pemulangan?kunjungan=<id>`.
 */
const props = defineProps<{ izin: string; judul: string }>()
const route = useRoute()
const qc = useQueryClient()

const kunjunganId = computed(() => (typeof route.query.kunjungan === 'string' ? route.query.kunjungan : ''))
const izinRencana = computed(() => boleh(IZIN_PEMULANGAN.rencanaLihat))
const izinResume = computed(() => boleh(IZIN_PEMULANGAN.lihat))
const izinPeta = computed(() => boleh('rawat_inap.peta_tempat_tidur.lihat'))

// Peta TT sekali baca (bukan polling): pemilih pasien dan TT pasien yang sedang dibuka.
const kunciPeta = [...KUNCI_PETA.semua, 'pemulangan'] as const
const peta = useQuery({
  queryKey: kunciPeta,
  queryFn: async () => {
    const r = await bacaPetaTempatTidur({ gedung_id: null, bangsal_id: null })
    return r.berubah ? r.data : { data: [], meta: { terpotong: false } }
  },
  enabled: izinPeta,
})
const berpasien = computed<TempatTidurPeta[]>(() => (peta.data.value?.data ?? []).filter((tt) => !!tt.kunjungan_rawat_inap_id && !!tt.pasien_rawat_inap && !tt.ditutup))
const ttPasien = computed(() => berpasien.value.find((tt) => tt.kunjungan_rawat_inap_id === kunjunganId.value) ?? null)

const rencana = useQuery({
  queryKey: computed(() => KUNCI_PEMULANGAN.rencana(kunjunganId.value)),
  queryFn: () => bacaRencanaPulang(kunjunganId.value),
  enabled: computed(() => !!kunjunganId.value && izinRencana.value),
})
const resume = useQuery({
  queryKey: computed(() => KUNCI_PEMULANGAN.resume(kunjunganId.value)),
  queryFn: () => bacaResumeMedis(kunjunganId.value),
  enabled: computed(() => !!kunjunganId.value && izinResume.value),
})

const pasien = computed(() => rencana.data.value?.pasien ?? resume.data.value?.pasien ?? ttPasien.value?.pasien_rawat_inap ?? null)
const statusKunjungan = computed(() => rencana.data.value?.kunjungan_status ?? null)
/** Masih menempati TT: dari rencana (status kunjungan berjalan + hari rawat), atau dari peta bila rencana tidak terbaca. */
const masihDirawat = computed(() => {
  const r = rencana.data.value
  if (r) return ['terdaftar', 'dilayani'].includes(r.kunjungan_status) && r.hari_rawat !== null
  return ttPasien.value !== null
})
const sudahPulang = computed(() => statusKunjungan.value === 'selesai' || pulangBaru.value !== null)
const memuatKepala = computed(() => (izinRencana.value && rencana.isPending.value) || (izinResume.value && resume.isPending.value) || (izinPeta.value && peta.isPending.value))
const galatKepala = computed(() => (izinRencana.value && rencana.isError.value ? rencana.error.value : null))

// ── Pulangkan ──
const bolehPulangkan = computed(() => boleh(IZIN_PEMULANGAN.input) && masihDirawat.value && !sudahPulang.value)
const dialogPulang = ref(false)
const pulangBaru = ref<{ hasil: RawatInap; tujuan: TujuanPulang } | null>(null)
async function muatUlang() {
  await Promise.all([
    qc.invalidateQueries({ queryKey: KUNCI_PEMULANGAN.rencana(kunjunganId.value) }),
    qc.invalidateQueries({ queryKey: KUNCI_PEMULANGAN.resume(kunjunganId.value) }),
    qc.invalidateQueries({ queryKey: KUNCI_PETA.semua }),
  ])
}
async function sesudahPulang(hasil: RawatInap, tujuan: TujuanPulang) {
  const tt = ttPasien.value?.kode ?? null
  pulangBaru.value = { hasil, tujuan }
  await muatUlang()
  const sah = resume.data.value?.status === 'sah'
  tampilkanToast(`${hasil.pasien.nama} keluar rawat inap (${LABEL_TUJUAN[tujuan]}).${tt ? ` TT ${tt} kini Dibersihkan.` : ''}${sah ? '' : ' Resume medis belum sah.'}`, 'ok')
}
async function sesudahBasi(pesan: string) {
  await muatUlang()
  tampilkanToast(pesan, 'error')
}
const resumeBelumSah = computed(() => sudahPulang.value && (!izinResume.value || resume.data.value?.status !== 'sah'))
</script>

<template>
  <HalamanModul :izin="props.izin" :judul="props.judul" uraian="Rencana pulang, daftar periksa, resume medis, dan konfirmasi keluar pasien rawat inap.">
    <!-- Tanpa pasien terpilih: pasien dirawat dari peta TT. -->
    <section v-if="!kunjunganId" class="kartu" aria-labelledby="judul-pilih-pasien">
      <div class="kartu-kepala">
        <div>
          <h2 id="judul-pilih-pasien">Pasien dirawat</h2>
          <p>Pilih pasien untuk membuka rencana pulang dan resume medisnya. Daftar ini mengikuti peta TT di cakupan Anda.</p>
        </div>
      </div>
      <KeadaanKosong v-if="!izinPeta" judul="Pilih pasien dari peta tempat tidur" uraian="Akun Anda tidak memegang izin lihat peta TT. Buka layar ini dari tugas resume medis atau minta izin lihat peta TT." />
      <KeadaanKosong v-else-if="peta.isPending.value" jenis="memuat" judul="Memuat pasien dirawat" />
      <KeadaanKosong v-else-if="peta.isError.value" jenis="error" judul="Daftar pasien gagal dimuat" :uraian="pesanError(peta.error.value)">
        <template #aksi><Tombol @click="peta.refetch()">Coba lagi</Tombol></template>
      </KeadaanKosong>
      <KeadaanKosong v-else-if="!berpasien.length" judul="Tidak ada pasien dirawat yang bisa dibuka" uraian="Belum ada TT terisi di cakupan Anda, atau izin Anda tidak membuka identitas pasien di bangsal itu." />
      <ul v-else class="daftar-pasien">
        <li v-for="tt in berpasien" :key="tt.id">
          <RouterLink :to="rutePemulangan(tt.kunjungan_rawat_inap_id!)" class="baris-pasien">
            <span class="nama">{{ tt.pasien_rawat_inap!.nama }}</span>
            <span class="ket">No. RM {{ formatNoRm(tt.pasien_rawat_inap!.no_rm) }} · lahir {{ formatTanggal(tt.pasien_rawat_inap!.tanggal_lahir) ?? '-' }}</span>
            <span class="ket"><code>{{ tt.kode }}</code> · {{ tt.bangsal.nama }}<template v-if="teksHariRawat(tt)"> · {{ teksHariRawat(tt) }}</template></span>
            <Lencana :jenis="tandaStatus(tt).jenis" tanpa-ikon><Ikon :nama="tandaStatus(tt).ikon" :ukuran="12" />{{ tandaStatus(tt).label }}</Lencana>
          </RouterLink>
        </li>
      </ul>
    </section>

    <template v-else>
      <RouterLink :to="{ path: $route.path }" class="kembali"><Ikon nama="kembali" :ukuran="16" />Daftar pasien dirawat</RouterLink>

      <section class="kartu kepala-pasien" aria-labelledby="judul-pasien">
        <KeadaanKosong v-if="memuatKepala && !pasien" jenis="memuat" judul="Memuat identitas pasien" />
        <KeadaanKosong v-else-if="!pasien" jenis="error" judul="Pasien tidak bisa dibuka" :uraian="galatKepala ? pesanError(galatKepala) : 'Kunjungan ini tidak ditemukan di cakupan Anda, atau pasien sudah tidak menempati TT.'" />
        <div v-else class="kartu-isi identitas">
          <h2 id="judul-pasien">{{ pasien.nama }}</h2>
          <dl>
            <div>
              <dt>No. RM</dt>
              <dd><code>{{ formatNoRm(pasien.no_rm) }}</code></dd>
            </div>
            <div>
              <dt>Tanggal lahir</dt>
              <dd>{{ formatTanggal(pasien.tanggal_lahir) ?? '-' }}</dd>
            </div>
            <div v-if="ttPasien">
              <dt>TT</dt>
              <dd><code>{{ ttPasien.kode }}</code> · {{ ttPasien.bangsal.nama }}</dd>
            </div>
            <div v-if="rencana.data.value?.hari_rawat">
              <dt>Hari rawat</dt>
              <dd>Hari ke-{{ rencana.data.value.hari_rawat }}</dd>
            </div>
            <div v-if="statusKunjungan || pulangBaru">
              <dt>Status</dt>
              <dd>
                <Lencana v-if="sudahPulang" jenis="netral">Sudah pulang</Lencana>
                <Lencana v-else jenis="info">{{ LABEL_STATUS_KUNJUNGAN[statusKunjungan ?? ''] ?? statusKunjungan }}</Lencana>
              </dd>
            </div>
          </dl>
          <Tombol v-if="bolehPulangkan" varian="bahaya" class="tombol-pulang" @click="dialogPulang = true">Pulangkan pasien</Tombol>
        </div>
      </section>

      <div v-if="resumeBelumSah" class="blok" role="status">
        <Pesan jenis="peringatan">
          Pasien sudah pulang dan resume medisnya belum sah. DPJP perlu mengesahkannya dalam 2 × 24 jam sejak pulang; tugas pengingat sudah dikirim ke DPJP.
        </Pesan>
      </div>

      <PanelRencanaPulang v-if="izinRencana" :kunjungan-id="kunjunganId" class="blok" />
      <PanelResumeMedis v-if="izinResume" :kunjungan-id="kunjunganId" class="blok" />
      <p v-if="pulangBaru" class="ket blok">Keluar: {{ LABEL_TUJUAN[pulangBaru.tujuan] }}. <RouterLink to="/rawat-inap/peta-tempat-tidur">Kembali ke peta TT</RouterLink></p>

      <DialogPulangkan
        v-model:open="dialogPulang"
        :kunjungan-id="kunjunganId"
        :pasien="pasien"
        :daftar-periksa="izinRencana ? (rencana.data.value?.daftar_periksa ?? []) : null"
        :tempat-tidur="ttPasien?.kode ?? null"
        :bangsal="ttPasien?.bangsal ?? null"
        @selesai="sesudahPulang"
        @basi="sesudahBasi"
      />
    </template>
  </HalamanModul>
</template>

<style scoped>
.blok {
  margin-top: var(--space-4);
}
.kembali {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  min-height: 44px;
  margin-bottom: var(--space-2);
}
.daftar-pasien {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: var(--space-2);
  margin: 0;
  padding: var(--space-3) var(--space-4) var(--space-4);
  list-style: none;
}
.baris-pasien {
  display: grid;
  gap: 4px;
  height: 100%;
  box-sizing: border-box;
  padding: var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-input);
  background: var(--surface);
  color: var(--text);
  text-decoration: none;
}
.baris-pasien:hover .nama {
  text-decoration: underline;
}
.baris-pasien:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
.baris-pasien .pil {
  justify-self: start;
}
.nama {
  font-weight: var(--font-weight-semibold);
}
.ket {
  font-size: 12.5px;
  color: var(--muted);
}
code {
  font-family: var(--font-mono);
}
/* Kepala pasien (PRD 5.1 butir 7): nama sebagai fokus layar, penanda kedua langsung di bawahnya. */
.identitas {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-3) var(--space-6);
}
.identitas h2 {
  flex-basis: 100%;
  margin: 0;
  font-size: 20px;
}
.identitas dl {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-3) var(--space-6);
  margin: 0;
}
.identitas dt {
  font-size: 12px;
  color: var(--muted);
}
.identitas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.tombol-pulang {
  margin-left: auto;
}
</style>
