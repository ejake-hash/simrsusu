<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import Lencana from '@/design-system/components/Lencana.vue'
import Tombol from '@/design-system/components/Tombol.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { ApiError } from '@/shared/api/klien'
import { refDebounced } from '@/shared/composables/refDebounced'
import { cariPasien } from '../api'
import { formatNoRm, formatTanggal } from '../pasien'
import type { Pasien } from '../tipe'

/**
 * Pemilih pasien satu kolom (pencarian 2.5) untuk layar pendaftaran kunjungan poli (2.6) dan IGD (2.23). Kata cari
 * lewat header X-Cari (cariPasien), bukan URL. Setiap baris menampilkan dua penanda identitas (5.1 butir 9).
 */
withDefaults(defineProps<{ idIsian: string; teksTidakCocok?: string }>(), {
  teksTidakCocok: 'Tidak ada pasien yang cocok. Daftarkan pasien baru di layar Pendaftaran pasien lebih dulu.',
})
const dipilih = defineModel<Pasien | null>({ required: true })

const cariKata = ref('')
const cariTunda = refDebounced(cariKata, 350)
const kata = computed(() => cariTunda.value.trim())
const kueri = useQuery({
  queryKey: computed(() => ['pilih-pasien', kata.value]),
  queryFn: () => cariPasien(kata.value),
  enabled: computed(() => kata.value.length > 0 && !dipilih.value),
})
const hasil = computed<Pasien[]>(() => kueri.data.value?.data ?? [])
const pesanBentuk = computed(() => {
  const e = kueri.error.value
  return e instanceof ApiError && (e.kode === 'kata_cari_tidak_dikenali' || e.kode === 'kata_cari_wajib') ? e.message : null
})
function pilih(p: Pasien) {
  dipilih.value = p
  cariKata.value = ''
}
const jk = (p: { jenis_kelamin: string }) => (p.jenis_kelamin === 'laki_laki' ? 'L' : p.jenis_kelamin === 'perempuan' ? 'P' : '—')
</script>

<template>
  <div v-if="dipilih" class="pasien-terpilih">
    <div>
      <span class="baris-pasien-utama">
        <b>{{ dipilih.nama || 'Tanpa nama' }}</b>
        <Lencana v-if="dipilih.tanpa_identitas" jenis="peringatan">Tanpa identitas</Lencana>
      </span>
      <span class="ket">No. RM {{ formatNoRm(dipilih.no_rm) }} · {{ formatTanggal(dipilih.tanggal_lahir) }} · {{ jk(dipilih) }}</span>
    </div>
    <Tombol varian="tautan" @click="dipilih = null">Ganti pasien</Tombol>
  </div>
  <template v-else>
    <div class="cari-baris">
      <Ikon nama="cari" :ukuran="18" />
      <label class="sr-only" :for="idIsian">Cari pasien</label>
      <input :id="idIsian" v-model="cariKata" type="search" class="isian isian-cari" autocomplete="off" placeholder="Cari pasien: NIK, No. RM, No. kartu BPJS, atau nama + tanggal lahir" />
    </div>
    <p v-if="pesanBentuk" class="tafsir" role="status">{{ pesanBentuk }}</p>
    <ul v-else-if="kata && hasil.length" class="hasil-pasien">
      <li v-for="p in hasil" :key="p.id">
        <button type="button" class="baris-pasien" @click="pilih(p)">
          <span class="baris-pasien-utama">
            <b>{{ p.nama || 'Tanpa nama' }}</b>
            <Lencana v-if="p.tanpa_identitas" jenis="peringatan" class="tanda">Tanpa identitas</Lencana>
            <Lencana v-if="p.meninggal_pada" jenis="netral" class="tanda">Meninggal</Lencana>
          </span>
          <span class="ket">No. RM {{ formatNoRm(p.no_rm) }} · {{ formatTanggal(p.tanggal_lahir) }} · {{ jk(p) }}</span>
        </button>
      </li>
    </ul>
    <p v-else-if="kata && !kueri.isPending.value" class="tafsir" role="status">{{ teksTidakCocok }}</p>
    <p v-else class="tafsir">Mulai dengan mencari pasien yang sudah terdaftar.</p>
  </template>
</template>

<style scoped>
.cari-baris {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--muted);
}
.isian-cari {
  flex: 1;
  min-width: 0;
}
.tafsir {
  margin: var(--space-2) 0 0;
  font-size: 13px;
  color: var(--muted);
}
.hasil-pasien {
  list-style: none;
  margin: var(--space-2) 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}
.baris-pasien {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  min-height: 44px;
  padding: var(--space-2) var(--space-3);
  text-align: left;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-input);
  cursor: pointer;
}
/* Bukan --accent: aksen tidak dipakai di layar IGD (DESIGN.md), dan komponen ini dipakai di sana. */
.baris-pasien:hover {
  border-color: var(--brand-600);
}
.baris-pasien-utama {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
.pasien-terpilih {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3);
  background: var(--bg);
  border-radius: var(--radius-input);
}
.ket {
  display: block;
  font-size: 12px;
  color: var(--muted);
}
</style>
