<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useSesiStore } from '@/app/sesi'
import { tampilkanToast } from '@/design-system/components/toast'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilPersetujuan, batalkanPersetujuan, setujuiPersetujuan, tolakPersetujuan } from '../../apiPersetujuan'
import { KUNCI, pesanError } from '../../pengaturan'
import { LABEL_STATUS_PERSETUJUAN, PIL_STATUS_PERSETUJUAN } from '../../persetujuan'
import type { PersetujuanAkses } from '../../tipePersetujuan'
import DialogAkhiri from '../pengaturan/DialogAkhiri.vue'
import DialogKeputusan from './DialogKeputusan.vue'

/**
 * Kartu rinci satu pengajuan: siapa, apa, dasar, dampak, kedaluwarsa, dan keadaan grup penyetuju. Tombol Setujui/Tolak
 * hanya bila server menyatakan `dapat_diputuskan` (penyetuju yang ditunjuk; MD-4) — superadmin yang bukan penyetujunya
 * tidak melihat tombol itu. Pengaju bisa menarik pengajuannya selama masih menunggu.
 */
const props = defineProps<{ id: string }>()
const emit = defineEmits<{ tutup: [] }>()
const qc = useQueryClient()
const sesi = useSesiStore()

const kueri = useQuery({ queryKey: computed(() => KUNCI.rinciPersetujuan(props.id)), queryFn: () => ambilPersetujuan(props.id) })
const p = computed(() => kueri.data.value ?? null)
const tidakDitemukan = computed(() => (kueri.error.value as { status?: number } | null)?.status === 404)
const milikSaya = computed(() => p.value?.diajukan_oleh?.id === sesi.pengguna?.id)

const LABEL_JENIS: Record<PersetujuanAkses['jenis'], string> = {
  izin_sensitif: 'Izin sensitif',
  peran_superadmin: 'Peran superadmin',
  pengecualian: 'Pengecualian per akun',
}

function pasang(baru: PersetujuanAkses) {
  qc.setQueryData(KUNCI.rinciPersetujuan(baru.id), baru)
  void qc.invalidateQueries({ queryKey: KUNCI.persetujuan, predicate: (q) => q.queryKey[2] === 'daftar' })
  // Isi peran/akun bisa berubah setelah diterapkan; menu akun terdampak ikut berubah.
  void qc.invalidateQueries({ queryKey: ['pengaturan'], predicate: (q) => q.queryKey[1] !== 'persetujuan' })
}

const mode = ref<'setujui' | 'tolak'>('setujui')
const dialogKeputusan = ref(false)
function putuskan(m: 'setujui' | 'tolak') {
  mode.value = m
  dialogKeputusan.value = true
}
/** Dilempar sebagai Error berpesan agar tampil di dialog; 409 tidak_bisa_diterapkan memuat ulang rincian. */
async function aksiKeputusan(teks: string | null) {
  const x = p.value!
  try {
    const baru = mode.value === 'setujui' ? await setujuiPersetujuan(x.id, teks) : await tolakPersetujuan(x.id, teks!)
    pasang(baru)
    tampilkanToast(
      mode.value === 'tolak'
        ? `${baru.nomor} ditolak.`
        : baru.diterapkan
          ? `${baru.nomor} disetujui dan sudah berlaku.`
          : `${baru.nomor} disetujui; menunggu ${baru.grup.jumlah - baru.grup.disetujui} penyetuju lain.`,
    )
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) void kueri.refetch()
    throw new Error(pesanError(e))
  }
}

const dialogTarik = ref(false)
async function tarik(alasan: string) {
  const baru = await batalkanPersetujuan(p.value!.id, alasan)
  pasang(baru)
  tampilkanToast(`Pengajuan ${baru.nomor} ditarik.`)
}
</script>

<template>
  <section class="kartu rincian" aria-labelledby="judul-rincian-persetujuan" :aria-busy="kueri.isFetching.value || undefined">
    <div v-if="tidakDitemukan" class="kartu-isi">
      <p class="catatan catatan-peringatan" role="alert">Pengajuan tidak ditemukan atau bukan untuk Anda.</p>
      <button type="button" class="tombol" @click="emit('tutup')">Tutup</button>
    </div>
    <div v-else-if="!p && kueri.isPending.value" class="kartu-isi" role="status">Memuat pengajuan…</div>
    <div v-else-if="!p" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <div class="kartu-kepala kepala">
        <div class="judul">
          <p class="nomor">{{ p.nomor }} · {{ LABEL_JENIS[p.jenis] }}</p>
          <h2 id="judul-rincian-persetujuan" tabindex="-1">{{ p.ringkasan }}</h2>
          <p class="status">
            <span class="pil" :class="PIL_STATUS_PERSETUJUAN[p.status]">{{ LABEL_STATUS_PERSETUJUAN[p.status] }}</span>
            <span v-if="p.diterapkan" class="pil pil-ok"><Ikon nama="centang" :ukuran="12" />Sudah berlaku</span>
          </p>
        </div>
        <div class="aksi">
          <template v-if="p.dapat_diputuskan">
            <button type="button" class="tombol tombol-bahaya" @click="putuskan('tolak')">Tolak</button>
            <button type="button" class="tombol tombol-utama" @click="putuskan('setujui')">Setujui</button>
          </template>
          <button v-else-if="milikSaya && p.status === 'menunggu'" type="button" class="tombol" @click="dialogTarik = true">Tarik pengajuan</button>
        </div>
      </div>
      <div class="kartu-isi">
        <p v-if="p.status === 'menunggu' && !p.dapat_diputuskan" class="catatan catatan-netral info-penyetuju">
          <Ikon nama="info" :ukuran="16" />
          <span>Diputuskan oleh pemegang peran {{ p.penyetuju_peran?.nama ?? 'penyetuju' }}{{ milikSaya ? '. Pengaju tidak bisa menyetujui pengajuannya sendiri.' : '.' }}</span>
        </p>
        <dl class="data">
          <div v-if="p.objek.peran">
            <dt>Peran</dt>
            <dd>{{ p.objek.peran.nama }}</dd>
          </div>
          <div v-if="p.objek.akun">
            <dt>Akun penerima</dt>
            <dd>{{ p.objek.akun.nama }} <span class="ket">({{ p.objek.akun.nama_pengguna }})</span></dd>
          </div>
          <div v-if="p.objek.izin">
            <dt>Izin</dt>
            <dd>{{ p.objek.izin.label }}</dd>
          </div>
          <div v-if="p.objek.cakupan_label">
            <dt>Cakupan</dt>
            <dd>{{ p.objek.cakupan_label }}</dd>
          </div>
          <div v-if="p.objek.berlaku_sampai">
            <dt>Berlaku sampai</dt>
            <dd>{{ formatTanggalWaktu(p.objek.berlaku_sampai) }}</dd>
          </div>
          <div>
            <dt>Dampak</dt>
            <dd>{{ p.akun_terdampak }} akun</dd>
          </div>
          <div>
            <dt>Penyetuju</dt>
            <dd>
              {{ p.penyetuju_peran?.nama ?? '—' }}
              <span v-if="p.grup.jumlah > 1" class="ket">· {{ p.grup.disetujui }} dari {{ p.grup.jumlah }} penyetuju sudah setuju</span>
            </dd>
          </div>
          <div>
            <dt>Diajukan</dt>
            <dd>{{ p.diajukan_oleh?.nama ?? 'Sistem' }}, {{ formatTanggalWaktu(p.diajukan_pada) }}</dd>
          </div>
          <div>
            <dt>Dasar</dt>
            <dd>{{ p.dasar }}</dd>
          </div>
          <div v-if="p.alasan">
            <dt>Alasan</dt>
            <dd>{{ p.alasan }}</dd>
          </div>
          <div v-if="p.status === 'menunggu'">
            <dt>Kedaluwarsa</dt>
            <dd>{{ formatTanggalWaktu(p.kedaluwarsa_pada) }} <span class="ket">(tanpa keputusan → otomatis kedaluwarsa)</span></dd>
          </div>
          <div v-if="p.diputuskan_pada">
            <dt>Keputusan</dt>
            <dd>
              {{ LABEL_STATUS_PERSETUJUAN[p.status] }} {{ p.diputuskan_oleh ? `oleh ${p.diputuskan_oleh.nama}` : 'oleh sistem' }}, {{ formatTanggalWaktu(p.diputuskan_pada) }}
              <span v-if="p.catatan_keputusan" class="ket">· {{ p.catatan_keputusan }}</span>
            </dd>
          </div>
        </dl>
      </div>
      <DialogKeputusan v-model:open="dialogKeputusan" :mode="mode" :persetujuan="p" :aksi="aksiKeputusan" />
      <DialogAkhiri
        v-model:open="dialogTarik"
        :judul="`Tarik pengajuan ${p.nomor}?`"
        :akibat="`Pengajuan “${p.ringkasan}” dibatalkan untuk semua penyetujunya dan tidak akan berlaku.`"
        :aksi="tarik"
        label-alasan="Alasan menarik"
        label-aksi="Tarik pengajuan"
      />
    </template>
  </section>
</template>

<style scoped>
.kepala {
  align-items: flex-start;
}
.judul {
  min-width: 0;
}
.judul h2 {
  font-size: 16px;
}
.judul h2:focus {
  outline: none;
}
.nomor {
  margin: 0 0 2px !important;
  font-family: var(--font-mono);
}
.status {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px !important;
}
.aksi {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-left: auto;
}
.info-penyetuju {
  margin: 0 0 var(--space-3);
}
.data {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
.ket {
  color: var(--muted);
  font-size: 12px;
}
</style>
