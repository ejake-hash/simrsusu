<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggal, formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilUnit, type Berversi } from '../api'
import { KUNCI_ORG, LABEL_JENIS } from '../organisasi'
import type { UnitOrganisasi } from '../tipe'

/**
 * Rincian satu unit (GET /unit-organisasi/{id}, ETag untuk ubah). Data ringkas dari pohon ditampilkan lebih dulu
 * agar tidak berkedip; tombol aksi baru aktif setelah ETag ada. Aksi disembunyikan bila izin tidak ada (server tetap
 * memeriksa lapis 3: akun ber-cakupan unit hanya mengubah subpohonnya).
 */
const props = defineProps<{ unitId: string; ringkas: UnitOrganisasi | null }>()
const emit = defineEmits<{ tambah: [induk: UnitOrganisasi]; ubah: [unit: Berversi<UnitOrganisasi>]; ubahStatus: [unit: Berversi<UnitOrganisasi>]; tutup: [] }>()

const kueri = useQuery({
  queryKey: computed(() => KUNCI_ORG.unit(props.unitId)),
  queryFn: () => ambilUnit(props.unitId),
  staleTime: 30_000,
})
const unit = computed(() => kueri.data.value ?? null)
const u = computed(() => unit.value?.data ?? props.ringkas)
const tidakDitemukan = computed(() => {
  const e = kueri.error.value as { status?: number } | null
  return e?.status === 404 || e?.status === 403
})

defineExpose({ unit })
</script>

<template>
  <section class="kartu rincian" aria-labelledby="judul-rincian-unit" :aria-busy="kueri.isFetching.value || undefined">
    <div v-if="tidakDitemukan" class="kartu-isi">
      <p class="catatan catatan-peringatan" role="alert">Unit tidak ditemukan atau di luar cakupan Anda.</p>
      <button type="button" class="tombol" @click="emit('tutup')">Tutup</button>
    </div>
    <div v-else-if="!u && kueri.isPending.value" class="kartu-isi" role="status">Memuat unit…</div>
    <div v-else-if="!u" class="kartu-isi" role="alert">
      <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
      <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
    </div>
    <template v-else>
      <div class="kartu-kepala kepala">
        <div class="judul">
          <nav v-if="u.jalur.length" class="jalur" aria-label="Unit induk">
            <span v-for="j in u.jalur" :key="j.id">{{ j.nama }}<Ikon nama="panah_kanan" :ukuran="12" /></span>
          </nav>
          <h2 id="judul-rincian-unit" tabindex="-1">{{ u.nama }}</h2>
          <p>
            <code>{{ u.kode }}</code> · {{ LABEL_JENIS[u.jenis] }}
            <span class="pil" :class="u.status === 'aktif' ? 'pil-ok' : 'pil-netral'">{{ u.status === 'aktif' ? 'Aktif' : 'Nonaktif' }}</span>
          </p>
        </div>
        <div class="aksi">
          <button v-if="boleh('master_data.organisasi.input') && u.status === 'aktif'" type="button" class="tombol" :disabled="!unit" @click="emit('tambah', u)">
            <Ikon nama="tambah" :ukuran="16" />Sub-unit
          </button>
          <template v-if="boleh('master_data.organisasi.perbaiki')">
            <button type="button" class="tombol" :disabled="!unit" @click="unit && emit('ubah', unit)">Ubah</button>
            <button type="button" class="tombol" :class="{ 'tombol-bahaya': u.status === 'aktif' }" :disabled="!unit" @click="unit && emit('ubahStatus', unit)">
              {{ u.status === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' }}
            </button>
          </template>
        </div>
      </div>
      <dl class="kartu-isi data">
        <div>
          <dt>Unit induk</dt>
          <dd>{{ u.jalur.length ? u.jalur[u.jalur.length - 1]!.nama : 'Tidak ada (unit teratas)' }}</dd>
        </div>
        <div>
          <dt>Sub-unit langsung</dt>
          <dd>{{ u.jumlah_anak }}</dd>
        </div>
        <div>
          <dt>Kepala unit</dt>
          <dd>{{ u.kepala?.nama ?? '—' }}<span v-if="!u.kepala" class="ket"> (diisi dari data pegawai)</span></dd>
        </div>
        <div>
          <dt>Berlaku dari</dt>
          <dd>{{ formatTanggal(u.berlaku_dari) ?? '—' }}</dd>
        </div>
        <div>
          <dt>Terakhir diubah</dt>
          <dd>{{ formatTanggalWaktu(u.diubah_pada) ?? '—' }}</dd>
        </div>
      </dl>
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
.aksi {
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
.ket {
  color: var(--muted);
  font-size: 12px;
}
</style>
