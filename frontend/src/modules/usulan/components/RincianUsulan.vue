<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useSesiStore } from '@/app/sesi'
import { tampilkanToast } from '@/design-system/components/toast'
import TtdModal from '@/design-system/components/TtdModal.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import StatusTandaTangan from '@/modules/ttd/components/StatusTandaTangan.vue'
import { useTandaTangan } from '@/modules/ttd/ttd'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilUsulan } from '../api'
import { KUNCI_USULAN, LABEL_AKSI_BUTIR, LABEL_JENIS_DATA, LABEL_RIWAYAT_USULAN, LABEL_TAHAP, LANGKAH_ALUR, PIL_TAHAP } from '../usulan'
import type { UsulanDataInduk } from '../tipe'
import DialogTindakanUsulan from './DialogTindakanUsulan.vue'
import DialogUsulan from './DialogUsulan.vue'

/**
 * Rincian usulan (tiket 1.8c fe 1): perbedaan lama → baru per butir (`tampilan[]` disusun server), peringatan bila
 * objek berubah sejak diusulkan, alur bertahap dengan pelakunya, riwayat per putaran, dan tindakan sesuai `dapat.*`
 * (verifikasi, setujui, kembalikan dengan catatan, kirim ulang). Pengusul/pemverifikasi/penyetuju bisa menandatangani
 * isi putaran itu dengan TTE T1 (1.6).
 */
const props = defineProps<{ id: string }>()
const emit = defineEmits<{ tutup: [] }>()
const qc = useQueryClient()
const sesi = useSesiStore()

const kueri = useQuery({ queryKey: computed(() => KUNCI_USULAN.rinci(props.id)), queryFn: () => ambilUsulan(props.id) })
const u = computed(() => kueri.data.value ?? null)
const tidakDitemukan = computed(() => [403, 404].includes((kueri.error.value as { status?: number } | null)?.status ?? 0))

const urutan: UsulanDataInduk['tahap'][] = ['diusulkan', 'diverifikasi', 'disetujui', 'berlaku']
/** Indeks langkah yang sudah dilalui; dikembalikan/gagal berhenti di langkah sebelumnya. */
const langkahKini = computed(() => {
  const t = u.value?.tahap
  if (!t) return 0
  if (t === 'dikembalikan') return u.value!.diverifikasi_oleh ? 1 : 0
  if (t === 'gagal_diterapkan') return 2
  return urutan.indexOf(t)
})
const pelakuLangkah = (i: number) => (i === 0 ? u.value?.diusulkan_oleh?.nama : i === 1 ? u.value?.diverifikasi_oleh?.nama : i === 2 ? u.value?.disetujui_oleh?.nama : i === 3 && u.value?.tahap === 'berlaku' ? 'Sistem' : null)
const riwayatPutaran = computed(() => {
  const kelompok = new Map<number, UsulanDataInduk['riwayat']>()
  for (const r of u.value?.riwayat ?? []) kelompok.set(r.putaran, [...(kelompok.get(r.putaran) ?? []), r])
  return [...kelompok.entries()].sort((a, b) => b[0] - a[0])
})

function segarkan(baru?: UsulanDataInduk) {
  if (baru) qc.setQueryData(KUNCI_USULAN.rinci(baru.id), baru)
  void qc.invalidateQueries({ queryKey: KUNCI_USULAN.semua, predicate: (q) => q.queryKey[1] === 'daftar' })
}

const dialogTindakan = ref(false)
const tindakan = ref<'verifikasi' | 'setujui' | 'kembalikan'>('verifikasi')
function buka(t: 'verifikasi' | 'setujui' | 'kembalikan') {
  tindakan.value = t
  dialogTindakan.value = true
}
function selesaiTindakan(baru: UsulanDataInduk) {
  segarkan(baru)
  tampilkanToast(
    baru.tahap === 'diverifikasi'
      ? `${baru.nomor} diverifikasi; diteruskan ke penyetuju.`
      : baru.tahap === 'disetujui'
        ? `${baru.nomor} disetujui; berlaku otomatis ${formatTanggal(`${baru.berlaku_dari}T00:00:00`)} pukul 00.00.`
        : baru.tahap === 'berlaku'
          ? `${baru.nomor} disetujui dan langsung berlaku.`
          : baru.tahap === 'gagal_diterapkan'
            ? `${baru.nomor} disetujui tetapi gagal diterapkan: ${baru.gagal_alasan ?? ''}`
            : `${baru.nomor} dikembalikan ke pengusul.`,
    baru.tahap === 'gagal_diterapkan' ? 'error' : 'ok',
  )
}
const dialogKirimUlang = ref(false)
function terkirimUlang(baru: UsulanDataInduk) {
  segarkan(baru)
  tampilkanToast(`${baru.nomor} dikirim ulang (putaran ${baru.putaran}) untuk verifikasi.`)
}

// ── TTE (1.6) ──
const dialogTtd = ref(false)
const tanganiTtd = useTandaTangan()
const pengguna = computed(() => ({ id: sesi.pengguna?.id ?? '', nama: sesi.pengguna?.pegawai?.nama ?? sesi.pengguna?.nama_pengguna ?? '' }))
const handlerTtd = computed(() =>
  tanganiTtd([{ jenis: 'usulan_data_induk', id: props.id }], () => {
    tampilkanToast(`Usulan ${u.value?.nomor ?? ''} ditandatangani.`)
    void kueri.refetch()
  }),
)
</script>

<template>
  <section class="kartu rincian" aria-labelledby="judul-rincian-usulan" :aria-busy="kueri.isFetching.value || undefined">
    <div v-if="!u && kueri.isPending.value" class="kartu-isi keadaan" role="status">Memuat usulan…</div>
    <div v-else-if="!u" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ tidakDitemukan ? 'Usulan tidak ditemukan atau tidak boleh Anda lihat.' : pesanError(kueri.error.value) }}</p>
      <button v-if="tidakDitemukan" type="button" class="tombol" @click="emit('tutup')">Tutup</button>
      <button v-else type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <div class="kartu-kepala">
        <div>
          <p class="nomor"><code>{{ u.nomor }}</code> · {{ LABEL_JENIS_DATA[u.jenis_data] ?? u.jenis_data }}<template v-if="u.putaran > 1"> · putaran {{ u.putaran }}</template></p>
          <h2 id="judul-rincian-usulan" tabindex="-1">{{ u.ringkasan }}</h2>
        </div>
        <span class="pil" :class="PIL_TAHAP[u.tahap]">{{ LABEL_TAHAP[u.tahap] }}</span>
      </div>
      <div class="kartu-isi">
        <p v-if="u.tahap === 'gagal_diterapkan'" class="catatan catatan-bahaya" role="alert">
          <Ikon nama="peringatan" :ukuran="16" />
          <span>Gagal diterapkan: {{ u.gagal_alasan }}. Usulan ini final; buat usulan baru bila masih perlu.</span>
        </p>
        <p v-if="u.tahap === 'disetujui'" class="catatan catatan-info">
          <Ikon nama="info" :ukuran="16" />
          <span>Versi baru berlaku otomatis {{ formatTanggal(`${u.berlaku_dari}T00:00:00`) }} pukul 00.00 waktu RS.</span>
        </p>

        <h3>Perubahan</h3>
        <ol class="butir">
          <li v-for="(b, i) in u.perubahan" :key="i" :class="{ berubah: b.objek_berubah }">
            <div class="butir-kepala">
              <b>{{ LABEL_AKSI_BUTIR[`${b.objek_jenis}.${b.aksi}`] ?? `${b.aksi} ${b.objek_jenis}` }}</b>
              <span>{{ b.objek_label }}</span>
            </div>
            <p v-if="b.objek_berubah" class="peringatan"><Ikon nama="peringatan" :ukuran="14" />Objek ini berubah sejak diusulkan; penerapan akan gagal. Kembalikan untuk diperbarui.</p>
            <table class="diff">
              <caption class="sr-only">Perbedaan {{ b.objek_label }}</caption>
              <thead>
                <tr>
                  <th scope="col">Isian</th>
                  <th scope="col">Lama</th>
                  <th scope="col"><span class="sr-only">menjadi</span></th>
                  <th scope="col">Baru</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in b.tampilan" :key="t.isian">
                  <th scope="row">{{ t.label_isian }}</th>
                  <td class="lama">{{ t.lama ?? '—' }}</td>
                  <td aria-hidden="true">→</td>
                  <td class="baru">{{ t.baru ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </li>
        </ol>

        <dl class="info">
          <div>
            <dt>Berlaku mulai</dt>
            <dd>{{ formatTanggal(`${u.berlaku_dari}T00:00:00`) }}</dd>
          </div>
          <div>
            <dt>Diusulkan</dt>
            <dd>{{ u.diusulkan_oleh?.nama ?? '—' }}, {{ formatTanggalWaktu(u.diusulkan_pada) }}</dd>
          </div>
          <div>
            <dt>Dasar</dt>
            <dd>{{ u.dasar }}</dd>
          </div>
          <div>
            <dt>Alasan</dt>
            <dd>{{ u.alasan }}</dd>
          </div>
        </dl>

        <h3>Alur</h3>
        <ol class="alur">
          <li
            v-for="(l, i) in LANGKAH_ALUR"
            :key="l.tahap"
            :class="{ selesai: i <= langkahKini && !(i === langkahKini && (u.tahap === 'dikembalikan' || u.tahap === 'gagal_diterapkan')) && (i < langkahKini || u.tahap === l.tahap || u.tahap === 'berlaku'), kini: i === langkahKini + 1 && !['berlaku', 'gagal_diterapkan', 'dikembalikan'].includes(u.tahap) }"
          >
            <span class="titik" aria-hidden="true">{{ i + 1 }}</span>
            <span>{{ l.label }}</span>
            <small>{{ pelakuLangkah(i) ?? '' }}</small>
          </li>
        </ol>

        <div v-if="u.dapat.verifikasi || u.dapat.setujui || u.dapat.kembalikan || u.dapat.kirim_ulang" class="aksi">
          <button v-if="u.dapat.kembalikan" type="button" class="tombol" @click="buka('kembalikan')">Kembalikan</button>
          <button v-if="u.dapat.verifikasi" type="button" class="tombol tombol-utama" @click="buka('verifikasi')">Verifikasi</button>
          <button v-if="u.dapat.setujui" type="button" class="tombol tombol-utama" @click="buka('setujui')">Setujui</button>
          <button v-if="u.dapat.kirim_ulang" type="button" class="tombol tombol-utama" @click="dialogKirimUlang = true">Perbaiki dan kirim ulang</button>
        </div>
        <p v-else-if="['diusulkan', 'diverifikasi'].includes(u.tahap)" class="ket">
          Pengusul, pemverifikasi, dan penyetuju harus tiga orang berbeda; tindakan tahap ini dilakukan pemegang izin lain.
        </p>

        <section class="ttd" aria-labelledby="judul-ttd-usulan">
          <div class="ttd-kepala">
            <h3 id="judul-ttd-usulan">Tanda tangan</h3>
            <template v-if="u.dapat.tandatangani">
              <button v-if="sesi.pengguna?.ttd?.aktif" type="button" class="tombol" @click="dialogTtd = true">Tandatangani usulan</button>
              <RouterLink v-else to="/tanda-tangan" class="tombol">Aktifkan TTE untuk menandatangani</RouterLink>
            </template>
          </div>
          <StatusTandaTangan :key="`${u.id}-${u.putaran}`" jenis="usulan_data_induk" :objek-id="u.id" />
        </section>

        <h3>Riwayat</h3>
        <div v-for="[putaran, daftar] in riwayatPutaran" :key="putaran" class="putaran">
          <p v-if="riwayatPutaran.length > 1" class="ket">Putaran {{ putaran }}</p>
          <ol class="riwayat">
            <li v-for="(r, i) in daftar" :key="i">
              <b>{{ LABEL_RIWAYAT_USULAN[r.aksi] ?? r.aksi }}</b> · {{ r.oleh?.nama ?? 'Sistem' }}, {{ formatTanggalWaktu(r.pada) }}
              <span v-if="r.catatan" class="ket">“{{ r.catatan }}”</span>
            </li>
          </ol>
        </div>
      </div>

      <DialogTindakanUsulan v-model:open="dialogTindakan" :usulan="u" :tindakan="tindakan" @selesai="selesaiTindakan" @muat-ulang="kueri.refetch()" />
      <DialogUsulan v-model:open="dialogKirimUlang" :usulan="u" @selesai="terkirimUlang" />
      <TtdModal
        v-model:open="dialogTtd"
        :objek="[{ id: u.id, judul: `${u.nomor} · putaran ${u.putaran}`, ringkasan: u.ringkasan, peringatan: u.perubahan.some((b) => b.objek_berubah) ? 'Ada objek yang berubah sejak diusulkan.' : null }]"
        :pengguna="pengguna"
        :sesi-berlaku-sampai="sesi.pengguna?.ttd?.sesi_berlaku_sampai ?? null"
        :tandatangani="handlerTtd"
      />
    </template>
  </section>
</template>

<style scoped>
.rincian {
  min-width: 0;
}
.keadaan {
  color: var(--muted);
}
.nomor {
  margin: 0 0 2px;
  color: var(--muted);
  font-size: 12.5px;
}
code {
  font-family: var(--font-mono);
}
h2 {
  font-size: 16px;
}
h2:focus {
  outline: none;
}
h3 {
  margin: var(--space-4) 0 var(--space-2);
  font-size: 14px;
}
.catatan {
  margin: 0 0 var(--space-3);
}
.butir {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}
.butir > li {
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
}
.butir > li.berubah {
  box-shadow: inset 3px 0 0 var(--warn);
}
.butir-kepala {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}
.peringatan {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 0 0 var(--space-2);
  color: var(--warn);
  font-size: 12.5px;
}
.diff {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.diff th,
.diff td {
  padding: 4px var(--space-2);
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: top;
}
.diff thead th {
  color: var(--muted);
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
}
.diff tbody th {
  font-weight: var(--font-weight-semibold);
}
.lama {
  color: var(--muted);
  text-decoration: line-through;
}
.baru {
  font-weight: var(--font-weight-semibold);
}
.info {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--space-3);
  margin: var(--space-4) 0 0;
}
.info dt {
  color: var(--muted);
  font-size: 12px;
}
.info dd {
  margin: 2px 0 0;
}
.alur {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.alur li {
  display: grid;
  justify-items: start;
  gap: 2px;
  font-size: 12.5px;
  color: var(--muted);
}
.alur .titik {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 1px solid var(--border-input);
  border-radius: 50%;
}
.alur li.selesai {
  color: var(--text);
}
.alur li.selesai .titik {
  border-color: var(--success);
  background: var(--success-bg);
}
.alur li.kini .titik {
  border-color: var(--brand-600);
  box-shadow: var(--ring);
}
.alur small {
  font-size: 11.5px;
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin-top: var(--space-4);
}
.ket {
  margin: var(--space-2) 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
.ttd {
  margin-top: var(--space-4);
  padding-top: var(--space-3);
  border-top: 1px solid var(--border);
}
.ttd-kepala {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
}
.ttd-kepala h3 {
  margin: 0;
}
.riwayat {
  display: grid;
  gap: var(--space-1);
  margin: 0;
  padding-left: var(--space-4);
  font-size: 13px;
}
@media (max-width: 599.98px) {
  .alur {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
