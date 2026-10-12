<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import type { Berversi } from '../../api'
import { ambilKamar, ambilLokasi, ambilTempatTidur, daftarPoliklinik, semuaTempatTidurKamar } from '../../apiLokasi'
import {
  JENIS_ANAK,
  KUNCI_LOKASI,
  LABEL_FUNGSI,
  LABEL_JENIS_KELAMIN,
  LABEL_JENIS_LOKASI,
  LABEL_STATUS_LOKASI,
  LABEL_STATUS_TT,
  PIL_STATUS_LOKASI,
  PIL_STATUS_TT,
} from '../../lokasi'
import type { Kamar, Lokasi, TempatTidur } from '../../tipeLokasi'
import DialogKamar from './DialogKamar.vue'
import DialogTempatTidur from './DialogTempatTidur.vue'
import DialogTutupTt from './DialogTutupTt.vue'
import RiwayatVersi from '@/modules/usulan/components/RiwayatVersi.vue'

/**
 * Panel rinci satu lokasi (GET /lokasi/{id}, ETag untuk ubah). Ruang: panel kamar (bangsal, kapasitas, peringatan
 * melebihi kapasitas, atribut) dan daftar TT-nya, atau tombol "Jadikan kamar"; juga poliklinik di ruang itu.
 * Node TT: rincian TT (kelas, status operasional, masa berlaku) dengan Ubah/Tutup. Aksi disembunyikan bila izin tidak
 * ada (server tetap memeriksa).
 */
const props = defineProps<{ lokasiId: string; ringkas: Lokasi | null }>()
const emit = defineEmits<{ tambah: [induk: Lokasi]; ubah: [lokasi: Berversi<Lokasi>]; status: [lokasi: Berversi<Lokasi>]; tutup: []; berubah: [] }>()
const qc = useQueryClient()

const kueri = useQuery({ queryKey: computed(() => KUNCI_LOKASI.rinci(props.lokasiId)), queryFn: () => ambilLokasi(props.lokasiId), staleTime: 30_000 })
const lokasi = computed(() => kueri.data.value ?? null)
const l = computed(() => lokasi.value?.data ?? props.ringkas)
const tidakDitemukan = computed(() => [403, 404].includes((kueri.error.value as { status?: number } | null)?.status ?? 0))

const kamarId = computed(() => l.value?.kamar_id ?? null)
const kamar = useQuery({ queryKey: computed(() => KUNCI_LOKASI.kamar(kamarId.value ?? '')), queryFn: () => ambilKamar(kamarId.value!), enabled: computed(() => !!kamarId.value) })
const ttKamar = useQuery({ queryKey: computed(() => KUNCI_LOKASI.ttKamar(kamarId.value ?? '')), queryFn: () => semuaTempatTidurKamar(kamarId.value!), enabled: computed(() => !!kamarId.value) })
const ttId = computed(() => l.value?.tempat_tidur_id ?? null)
const tt = useQuery({ queryKey: computed(() => KUNCI_LOKASI.tt(ttId.value ?? '')), queryFn: () => ambilTempatTidur(ttId.value!), enabled: computed(() => !!ttId.value) })
const poli = useQuery({
  queryKey: computed(() => [...KUNCI_LOKASI.daftarPoli({ lokasi_id: props.lokasiId })]),
  queryFn: async () => (await daftarPoliklinik({ lokasi_id: props.lokasiId }, null, 50)).data,
  enabled: computed(() => l.value?.jenis === 'ruang'),
})

const bolehInput = computed(() => boleh('master_data.organisasi.input'))
const bolehUbah = computed(() => boleh('master_data.organisasi.perbaiki'))
const bisaPunyaAnak = computed(() => !!l.value && JENIS_ANAK[l.value.jenis].length > 0 && l.value.status !== 'ditutup')
const atribut = computed(() => Object.entries(kamar.data.value?.data.atribut_standar ?? {}))
const teksAtribut = (v: boolean | number | string) => (v === true ? 'ya' : v === false ? 'tidak' : String(v))

// ── Dialog kamar dan TT ──
const dialogKamar = ref(false)
const dialogTt = ref(false)
const dialogTutup = ref(false)
const ttDiubah = ref<Berversi<TempatTidur> | null>(null)
function segarkanSemua() {
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.semua })
  void qc.invalidateQueries({ queryKey: KUNCI_LOKASI.bangsal })
  emit('berubah')
}
function kamarSelesai(hasil: Berversi<Kamar>) {
  qc.setQueryData(KUNCI_LOKASI.kamar(hasil.data.id), hasil)
  tampilkanToast(hasil.data.melebihi_kapasitas ? `Kamar ${hasil.data.lokasi.kode_lengkap} disimpan; TT melebihi kapasitas maksimum.` : `Kamar ${hasil.data.lokasi.kode_lengkap} disimpan.`)
  segarkanSemua()
}
async function bukaTt(t: TempatTidur | null, aksi: 'ubah' | 'tutup' = 'ubah') {
  ttDiubah.value = t ? await ambilTempatTidur(t.id).catch((e) => (tampilkanToast(pesanError(e), 'error'), null)) : null
  if (t && !ttDiubah.value) return
  if (aksi === 'tutup') dialogTutup.value = true
  else dialogTt.value = true
}
function ttSelesai(hasil: Berversi<TempatTidur>, peringatan: string[] = []) {
  qc.setQueryData(KUNCI_LOKASI.tt(hasil.data.id), hasil)
  tampilkanToast(
    peringatan.includes('melebihi_kapasitas')
      ? `TT ${hasil.data.kode} disimpan; jumlah TT kini melebihi kapasitas maksimum kamar.`
      : hasil.data.status_operasional === 'akan_ditutup'
        ? `TT ${hasil.data.kode} dijadwalkan tutup; terakhir dihitung ${formatTanggal(`${hasil.data.berlaku_sampai}T00:00:00`)}.`
        : `TT ${hasil.data.kode} disimpan.`,
    peringatan.length ? 'peringatan' : 'ok',
  )
  segarkanSemua()
}
const ttNode = computed(() => tt.data.value ?? null)
</script>

<template>
  <section class="kartu rincian" aria-labelledby="judul-rincian-lokasi" :aria-busy="kueri.isFetching.value || undefined">
    <div v-if="tidakDitemukan" class="kartu-isi">
      <p class="catatan catatan-peringatan" role="alert">Lokasi tidak ditemukan atau di luar izin Anda.</p>
      <button type="button" class="tombol" @click="emit('tutup')">Tutup</button>
    </div>
    <div v-else-if="!l && kueri.isPending.value" class="kartu-isi" role="status">Memuat lokasi…</div>
    <div v-else-if="!l" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <div class="kartu-kepala kepala">
        <div class="judul">
          <nav v-if="l.jalur.length" class="jalur" aria-label="Lokasi induk">
            <span v-for="j in l.jalur" :key="j.id">{{ j.nama }}<Ikon nama="panah_kanan" :ukuran="12" /></span>
          </nav>
          <h2 id="judul-rincian-lokasi" tabindex="-1">{{ l.nama }}</h2>
          <p>
            <code>{{ l.kode_lengkap }}</code> · {{ LABEL_JENIS_LOKASI[l.jenis] }}<template v-if="l.fungsi"> · {{ LABEL_FUNGSI[l.fungsi] }}</template>
            <span class="pil" :class="PIL_STATUS_LOKASI[l.status]">{{ LABEL_STATUS_LOKASI[l.status] }}</span>
          </p>
        </div>
        <div v-if="l.jenis !== 'tempat_tidur'" class="aksi">
          <button v-if="bolehInput && bisaPunyaAnak" type="button" class="tombol" :disabled="!lokasi" @click="emit('tambah', l)"><Ikon nama="tambah" :ukuran="16" />Tambah di bawah</button>
          <template v-if="bolehUbah">
            <button type="button" class="tombol" :disabled="!lokasi" @click="lokasi && emit('ubah', lokasi)">Ubah</button>
            <button type="button" class="tombol" :disabled="!lokasi" @click="lokasi && emit('status', lokasi)">Status</button>
          </template>
        </div>
      </div>

      <dl class="kartu-isi data">
        <div>
          <dt>Unit pengelola</dt>
          <dd>{{ l.unit ? `${l.unit.kode} · ${l.unit.nama}` : '—' }}</dd>
        </div>
        <div>
          <dt>Status berlaku sejak</dt>
          <dd>{{ formatTanggal(l.status_berlaku_dari ? `${l.status_berlaku_dari}T00:00:00` : null) ?? '—' }}</dd>
        </div>
        <div v-if="l.jenis !== 'tempat_tidur'">
          <dt>Anak langsung</dt>
          <dd>{{ l.jumlah_anak }}</dd>
        </div>
        <div>
          <dt>ID SATUSEHAT</dt>
          <dd>{{ l.satusehat_lokasi_id ?? 'Belum terdaftar' }}</dd>
        </div>
        <div>
          <dt>Terakhir diubah</dt>
          <dd>{{ formatTanggalWaktu(l.diubah_pada) ?? '—' }}</dd>
        </div>
      </dl>

      <!-- Ruang: kamar rawat dan TT-nya. -->
      <section v-if="l.jenis === 'ruang'" class="bagian" aria-labelledby="judul-kamar">
        <div class="kepala-bagian">
          <h3 id="judul-kamar">Kamar rawat</h3>
          <template v-if="kamarId && kamar.data.value">
            <button v-if="bolehUbah" type="button" class="tombol" @click="dialogKamar = true">Ubah kamar</button>
          </template>
          <button v-else-if="!kamarId && bolehInput && l.status === 'aktif'" type="button" class="tombol" @click="dialogKamar = true">Jadikan kamar</button>
        </div>
        <p v-if="!kamarId" class="ket">Ruang ini belum menjadi kamar rawat bangsal. Tempat tidur hanya bisa ditambahkan di kamar.</p>
        <p v-else-if="kamar.isPending.value" class="ket" role="status">Memuat kamar…</p>
        <template v-else-if="kamar.data.value">
          <dl class="data">
            <div>
              <dt>Bangsal</dt>
              <dd>{{ kamar.data.value.data.bangsal.kode }} · {{ kamar.data.value.data.bangsal.nama }}</dd>
            </div>
            <div>
              <dt>TT dihitung hari ini / kapasitas</dt>
              <dd>
                {{ kamar.data.value.data.jumlah_tempat_tidur_aktif }} / {{ kamar.data.value.data.kapasitas_maksimum ?? 'tanpa batas' }}
                <span v-if="kamar.data.value.data.melebihi_kapasitas" class="pil pil-peringatan"><Ikon nama="peringatan" :ukuran="12" />Melebihi kapasitas</span>
              </dd>
            </div>
            <div v-if="atribut.length">
              <dt>Atribut standar</dt>
              <dd>
                <ul class="atribut">
                  <li v-for="[k, v] in atribut" :key="k"><code>{{ k }}</code>: {{ teksAtribut(v) }}</li>
                </ul>
              </dd>
            </div>
          </dl>
          <div class="kepala-bagian">
            <h3 id="judul-tt">Tempat tidur ({{ ttKamar.data.value?.length ?? 0 }})</h3>
            <button v-if="bolehInput && l.status === 'aktif'" type="button" class="tombol" @click="bukaTt(null)"><Ikon nama="tambah" :ukuran="16" />Tambah TT</button>
          </div>
          <p v-if="ttKamar.isPending.value" class="ket" role="status">Memuat TT…</p>
          <ul v-else-if="ttKamar.data.value?.length" class="daftar-tt" aria-labelledby="judul-tt">
            <li v-for="t in ttKamar.data.value" :key="t.id" class="tt">
              <div>
                <b><code>{{ t.kode }}</code></b>
                <span class="pil" :class="PIL_STATUS_TT[t.status_operasional]">{{ LABEL_STATUS_TT[t.status_operasional] }}</span>
                <span v-if="t.ekstra" class="pil pil-info">Ekstra</span>
                <span v-if="t.isolasi" class="pil pil-netral">Isolasi</span>
                <p>{{ t.kelas_rawat.nama }} · {{ LABEL_JENIS_KELAMIN[t.aturan_jenis_kelamin] }} · dihitung {{ formatTanggal(`${t.berlaku_dari}T00:00:00`) }}<template v-if="t.berlaku_sampai"> s.d. {{ formatTanggal(`${t.berlaku_sampai}T00:00:00`) }}</template></p>
              </div>
              <span v-if="bolehUbah && ['aktif', 'belum_berlaku'].includes(t.status_operasional)" class="aksi-tt">
                <button type="button" class="tombol" :aria-label="`Ubah TT ${t.kode}`" @click="bukaTt(t)">Ubah</button>
                <button type="button" class="tombol tombol-bahaya" :aria-label="`Tutup TT ${t.kode}`" @click="bukaTt(t, 'tutup')">Tutup</button>
              </span>
            </li>
          </ul>
          <p v-else class="ket">Belum ada tempat tidur di kamar ini.</p>
        </template>
        <template v-if="poli.data.value?.length">
          <h3 class="judul-poli">Poliklinik di ruang ini</h3>
          <ul class="daftar-poli">
            <li v-for="p in poli.data.value" :key="p.id">{{ p.kode }} · {{ p.nama }} <span class="pil" :class="p.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ p.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span></li>
          </ul>
        </template>
        <!-- Riwayat versi 1.8c: ruang dan kamarnya. -->
        <RiwayatVersi jenis="ruang" :objek-id="l.id" />
        <RiwayatVersi v-if="kamarId" :key="kamarId" jenis="kamar" :objek-id="kamarId" />
      </section>

      <!-- Node TT: rincian tempat tidur. -->
      <section v-if="l.jenis === 'tempat_tidur'" class="bagian" aria-labelledby="judul-tt-node">
        <div class="kepala-bagian">
          <h3 id="judul-tt-node">Tempat tidur</h3>
          <span v-if="bolehUbah && ttNode && ['aktif', 'belum_berlaku'].includes(ttNode.data.status_operasional)" class="aksi-tt">
            <button type="button" class="tombol" @click="bukaTt(ttNode.data)">Ubah</button>
            <button type="button" class="tombol tombol-bahaya" @click="bukaTt(ttNode.data, 'tutup')">Tutup</button>
          </span>
        </div>
        <p v-if="tt.isPending.value" class="ket" role="status">Memuat TT…</p>
        <dl v-else-if="ttNode" class="data">
          <div>
            <dt>Kode TT</dt>
            <dd><code>{{ ttNode.data.kode }}</code> <span class="pil" :class="PIL_STATUS_TT[ttNode.data.status_operasional]">{{ LABEL_STATUS_TT[ttNode.data.status_operasional] }}</span></dd>
          </div>
          <div>
            <dt>Kamar · bangsal</dt>
            <dd>{{ ttNode.data.kamar.kode_lengkap }} · {{ ttNode.data.bangsal.nama }}</dd>
          </div>
          <div>
            <dt>Kelas rawat</dt>
            <dd>{{ ttNode.data.kelas_rawat.nama }}{{ ttNode.data.ekstra ? ' · ekstra' : '' }}{{ ttNode.data.isolasi ? ' · isolasi' : '' }}</dd>
          </div>
          <div>
            <dt>Jenis kelamin</dt>
            <dd>{{ LABEL_JENIS_KELAMIN[ttNode.data.aturan_jenis_kelamin] }}</dd>
          </div>
          <div>
            <dt>Dihitung</dt>
            <dd>{{ formatTanggal(`${ttNode.data.berlaku_dari}T00:00:00`) }} s.d. {{ ttNode.data.berlaku_sampai ? formatTanggal(`${ttNode.data.berlaku_sampai}T00:00:00`) : 'tanpa batas' }}</dd>
          </div>
          <div v-if="ttNode.data.alasan_tutup">
            <dt>Alasan tutup</dt>
            <dd>{{ ttNode.data.alasan_tutup }}</dd>
          </div>
        </dl>
        <RiwayatVersi v-if="ttId" :key="ttId" jenis="tempat_tidur" :objek-id="ttId" />
      </section>

      <template v-if="l.jenis === 'ruang'">
        <DialogKamar v-model:open="dialogKamar" :ruang="l" :kamar="kamar.data.value ?? null" @selesai="kamarSelesai" @muat-ulang="segarkanSemua" />
        <DialogTempatTidur v-if="kamar.data.value" v-model:open="dialogTt" :kamar="kamar.data.value.data" :tempat-tidur="ttDiubah" @selesai="ttSelesai" @muat-ulang="segarkanSemua" />
      </template>
      <DialogTempatTidur v-if="l.jenis === 'tempat_tidur' && ttNode" v-model:open="dialogTt" :kamar="{ id: ttNode.data.kamar.id, lokasi: { kode_lengkap: ttNode.data.kamar.kode_lengkap } }" :tempat-tidur="ttDiubah" @selesai="ttSelesai" @muat-ulang="segarkanSemua" />
      <DialogTutupTt v-model:open="dialogTutup" :tempat-tidur="ttDiubah" @selesai="(h) => ttSelesai(h)" @muat-ulang="segarkanSemua" />
    </template>
  </section>
</template>

<style scoped>
.rincian {
  scroll-margin-top: calc(var(--tinggi-atas-lekat, 60px) + var(--space-3));
}
.kepala {
  flex-wrap: wrap;
  align-items: flex-start;
  gap: var(--space-3);
}
.judul {
  min-width: 0;
}
.judul h2 {
  font-size: 17px;
}
.judul h2:focus {
  outline: none;
}
.judul p {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin: 2px 0 0;
}
.jalur {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  margin-bottom: 2px;
  color: var(--muted);
  font-size: 12px;
}
.jalur span {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}
.aksi,
.aksi-tt {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-left: auto;
}
.data {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3) var(--space-4);
  margin: 0;
}
dt {
  color: var(--muted);
  font-size: 12px;
}
dd {
  margin: 2px 0 0;
}
.bagian {
  padding: var(--space-3) var(--space-4) var(--space-4);
  border-top: 1px solid var(--border);
}
.kepala-bagian {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin: var(--space-3) 0 var(--space-2);
}
h3 {
  margin: 0;
  font-size: 11.5px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--subtle);
}
.judul-poli {
  margin-top: var(--space-4);
}
.ket {
  margin: 0;
  color: var(--muted);
  font-size: 12.5px;
}
.atribut {
  margin: 0;
  padding-left: 16px;
}
.daftar-tt,
.daftar-poli {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.tt {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.tt b {
  margin-right: 6px;
}
.tt p {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
.daftar-poli li {
  font-size: 13px;
}
</style>
