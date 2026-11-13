<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import Pesan from '@/design-system/components/Pesan.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { errorKolom } from '@/modules/akses/pengaturan'
import { pulangkanPasien } from '../api'
import {
  butirPerluAlasan,
  galatButirPulang,
  gabungButirAlasan,
  kurangPulang,
  LABEL_TUJUAN,
  pesanGalatPulang,
  pulangBasi,
  teksIdentitas,
  URUTAN_TUJUAN,
} from '../pemulangan'
import type { ButirDaftarPeriksaPulang, RawatInap, RingkasLokasiPeta, TujuanPulang } from '../tipe'

/**
 * Konfirmasi pulang (FR-05-03..05; PRD 7.00.3 baris rawat inap). Dua penanda identitas tampil di atas sebelum tombol
 * konfirmasi (PRD 5.1 butir 9); tanpa identitas tombol nonaktif. Butir wajib manual yang belum selesai diberi kolom alasan
 * masing-masing (pola tanggapan peringatan, 5.1 butir 12): dari rencana yang dibaca, ditambah rincian 422
 * `daftar_periksa_belum_lengkap` bila server menyebut butir lain. 404/409 status: dialog ditutup dan halaman dimuat ulang.
 */
const props = defineProps<{
  kunjunganId: string
  pasien: { nama: string; no_rm: string; tanggal_lahir: string | null } | null
  /** null = layar tidak memegang izin lihat rencana pulang; kolom alasan datang dari rincian 422. */
  daftarPeriksa: ButirDaftarPeriksaPulang[] | null
  tempatTidur: string | null
  bangsal: RingkasLokasiPeta | null
}>()
const buka = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ selesai: [hasil: RawatInap, tujuan: TujuanPulang]; basi: [pesan: string] }>()

const tujuan = ref<TujuanPulang | ''>('')
const alasan = ref('')
const tanggapan = ref<Record<string, string>>({})
const galat = ref<unknown>(null)
const mengirim = ref(false)

watch(buka, (b) => {
  if (!b) return
  tujuan.value = ''
  alasan.value = ''
  tanggapan.value = {}
  galat.value = null
})

const butir = computed(() => gabungButirAlasan(butirPerluAlasan(props.daftarPeriksa ?? []), galat.value))
const galatButir = computed(() => galatButirPulang(galat.value))
const galatIsian = computed(() => errorKolom(galat.value, ['alasan', 'tujuan']))
const pesan = computed(() => (galat.value ? pesanGalatPulang(galat.value) : null))
const kurang = computed(() => kurangPulang({ identitasAda: !!props.pasien, tujuan: tujuan.value, alasan: alasan.value, butir: butir.value, tanggapan: tanggapan.value }))

async function kirim() {
  const t = tujuan.value
  if (kurang.value || !t) return
  mengirim.value = true
  galat.value = null
  try {
    const hasil = await pulangkanPasien(props.kunjunganId, {
      tujuan: t,
      alasan: alasan.value.trim(),
      tanggapan: butir.value.map((b) => ({ kode: b.kode, alasan: (tanggapan.value[b.kode] ?? '').trim() })),
    })
    buka.value = false
    emit('selesai', hasil, t)
  } catch (e) {
    if (pulangBasi(e)) {
      buka.value = false
      emit('basi', pesanGalatPulang(e) ?? 'Data pasien sudah berubah. Halaman dimuat ulang.')
    } else galat.value = e
  } finally {
    mengirim.value = false
  }
}
</script>

<template>
  <Dialog
    v-model:open="buka"
    :judul="pasien ? `Pulangkan ${pasien.nama}?` : 'Pulangkan pasien?'"
    uraian="Cocokkan gelang identitas pasien dengan data ini. Sesudah dikonfirmasi, TT menjadi Dibersihkan dan kunjungan rawat inap selesai; langkah ini tidak bisa dibatalkan dari layar."
    lebar="lebar"
    :sibuk="mengirim"
    peringatan
  >
    <dl class="identitas">
      <div>
        <dt>Pasien</dt>
        <dd v-if="pasien">{{ teksIdentitas(pasien) }}</dd>
        <dd v-else class="tanpa-identitas">Identitas pasien belum termuat; muat ulang halaman sebelum memulangkan.</dd>
      </div>
      <div v-if="tempatTidur">
        <dt>TT sekarang</dt>
        <dd>{{ tempatTidur }}<template v-if="bangsal"> · {{ bangsal.nama }}</template></dd>
      </div>
    </dl>

    <form id="form-pulangkan" class="isi" novalidate @submit.prevent="kirim">
      <fieldset class="tujuan">
        <legend class="kolom-label">Cara keluar <span class="kolom-wajib" aria-hidden="true">*</span></legend>
        <label v-for="t in URUTAN_TUJUAN" :key="t" class="pilihan" :class="{ dipilih: tujuan === t }">
          <input v-model="tujuan" type="radio" name="tujuan-pulang" :value="t" />
          <span>{{ LABEL_TUJUAN[t] }}</span>
        </label>
        <p v-if="galatIsian.tujuan" class="kolom-error">{{ galatIsian.tujuan }}</p>
      </fieldset>
      <KolomIsian v-slot="a" label="Alasan atau catatan keluar" wajib petunjuk="Catatan klinis, 5 sampai 1.000 karakter. Tidak masuk log." :error="galatIsian.alasan">
        <textarea v-bind="a" v-model="alasan" class="isian" rows="2" maxlength="1000"></textarea>
      </KolomIsian>

      <fieldset v-if="butir.length" class="butir">
        <legend class="kolom-label">Butir wajib yang belum selesai</legend>
        <p class="ket">Selesaikan dulu di daftar periksa, atau tulis alasan per butir. Butir tagihan atau jaminan menunggu kasir dan tidak perlu alasan.</p>
        <KolomIsian v-for="b in butir" :key="b.kode" v-slot="a" :label="`Alasan belum selesai: ${b.nama}`" wajib :error="galatButir[b.kode]">
          <input v-bind="a" v-model="tanggapan[b.kode]" class="isian" maxlength="500" autocomplete="off" />
        </KolomIsian>
      </fieldset>
      <Pesan v-else-if="daftarPeriksa === null" jenis="info">Daftar periksa tidak tampil untuk akun Anda. Bila ada butir wajib yang belum selesai, server akan memintanya dan kolom alasan muncul di sini.</Pesan>

      <div v-if="pesan" role="alert">
        <Pesan jenis="error">{{ pesan }}</Pesan>
      </div>
      <p v-if="kurang" class="ket kurang">{{ kurang }}</p>
    </form>
    <template #aksi>
      <Tombol :disabled="mengirim || undefined" @click="buka = false">Batal</Tombol>
      <Tombol type="submit" form="form-pulangkan" varian="bahaya" :sibuk="mengirim" :disabled="kurang !== null || undefined">
        {{ pasien && tujuan ? `${LABEL_TUJUAN[tujuan]}: ${pasien.nama}` : 'Pulangkan' }}
      </Tombol>
    </template>
  </Dialog>
</template>

<style scoped>
.identitas {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-3);
}
.identitas dt {
  font-size: 12px;
  color: var(--muted);
}
.identitas dd {
  margin: 0;
  font-weight: var(--font-weight-semibold);
}
.tanpa-identitas {
  color: var(--danger);
}
.isi {
  display: grid;
  gap: var(--space-3);
}
.tujuan,
.butir {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  border: 0;
}
.pilihan {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  min-height: 44px;
  box-sizing: border-box;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--border-input);
  border-radius: var(--radius-input);
  background: var(--surface);
  cursor: pointer;
}
.pilihan:has(input:focus-visible) {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
/* Terpilih: tepi tebal dan teks tebal, bukan hanya warna. */
.pilihan.dipilih {
  border-width: 2px;
  border-color: var(--text);
  font-weight: var(--font-weight-semibold);
}
.ket {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}
</style>
