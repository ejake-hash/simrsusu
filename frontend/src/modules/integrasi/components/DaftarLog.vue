<script setup lang="ts">
import { useInfiniteQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import KolomIsian from '@/design-system/form/KolomIsian.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { daftarLog } from '../api'
import { KUNCI_INTEGRASI, LABEL_LINGKUNGAN, LABEL_SISTEM } from '../integrasi'
import type { FilterLog, SistemIntegrasi } from '../tipe'

/**
 * Log panggilan ke sistem luar (GET /integrasi/log, kontrak v0.13.0; izin integrasi.log.lihat). Isi permintaan/respons
 * sudah disamarkan server dan bisa sampai 16 KB, jadi tampil dalam lipatan; halaman 20 baris. Tidak disalin ke
 * penyimpanan peramban.
 */
const tujuan = ref<SistemIntegrasi | ''>('')
const hasil = ref<'' | 'berhasil' | 'gagal'>('')
const dari = ref('')
const sampai = ref('')
const salahTanggal = computed(() => (dari.value && sampai.value && dari.value > sampai.value ? 'Tidak boleh sebelum tanggal awal.' : undefined))
const filter = computed<FilterLog>(() => ({
  tujuan: tujuan.value || undefined,
  berhasil: hasil.value === '' ? undefined : hasil.value === 'berhasil',
  dari: dari.value || undefined,
  sampai: sampai.value || undefined,
}))
const kueri = useInfiniteQuery({
  queryKey: computed(() => KUNCI_INTEGRASI.log(filter.value)),
  queryFn: ({ pageParam }) => daftarLog(filter.value, pageParam),
  initialPageParam: null as string | null,
  getNextPageParam: (h) => h.meta.kursor_berikutnya,
  enabled: computed(() => !salahTanggal.value),
})
const baris = computed(() => kueri.data.value?.pages.flatMap((h) => h.data) ?? [])
</script>

<template>
  <section class="kartu" aria-labelledby="judul-log">
    <div class="kartu-kepala">
      <div>
        <h2 id="judul-log">Log integrasi</h2>
        <p>Satu baris per panggilan ke sistem luar, termasuk percobaan ulang dan uji koneksi. Data pribadi dan rahasia disamarkan.</p>
      </div>
    </div>
    <div class="filter">
      <KolomIsian v-slot="a" label="Tujuan">
        <select v-bind="a" v-model="tujuan" class="isian">
          <option value="">Semua</option>
          <option v-for="(l, k) in LABEL_SISTEM" :key="k" :value="k">{{ l }}</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Hasil">
        <select v-bind="a" v-model="hasil" class="isian">
          <option value="">Semua</option>
          <option value="berhasil">Berhasil</option>
          <option value="gagal">Gagal</option>
        </select>
      </KolomIsian>
      <KolomIsian v-slot="a" label="Dari tanggal">
        <input v-bind="a" v-model="dari" type="date" class="isian" />
      </KolomIsian>
      <KolomIsian v-slot="a" label="Sampai tanggal" :error="salahTanggal">
        <input v-bind="a" v-model="sampai" type="date" class="isian" />
      </KolomIsian>
    </div>
    <div class="kartu-isi">
      <p v-if="kueri.isPending.value && !salahTanggal" role="status" class="ket">Memuat log…</p>
      <div v-else-if="kueri.isError.value" role="alert">
        <p class="catatan catatan-bahaya">{{ pesanError(kueri.error.value) }}</p>
        <button type="button" class="tombol" @click="kueri.refetch()">Coba lagi</button>
      </div>
      <p v-else-if="!baris.length" class="ket">Tidak ada log yang cocok.</p>
      <ul v-else class="log" aria-label="Daftar log">
        <li v-for="l in baris" :key="l.id">
          <div class="baris">
            <span class="pil" :class="l.berhasil ? 'pil-ok' : 'pil-bahaya'">{{ l.berhasil ? 'Berhasil' : 'Gagal' }}</span>
            <code>{{ l.metode }} {{ l.endpoint }}</code>
          </div>
          <p class="ket">
            {{ formatTanggalWaktu(l.terjadi_pada) }} · {{ LABEL_SISTEM[l.tujuan] }} {{ LABEL_LINGKUNGAN[l.lingkungan].toLowerCase() }}
            <template v-if="l.kode_http"> · HTTP {{ l.kode_http }}</template><template v-if="l.latensi_ms !== null"> · {{ l.latensi_ms }} ms</template>
            <template v-if="!l.kotak_keluar_id"> · panggilan langsung</template>
          </p>
          <p v-if="l.pesan" class="pesan">{{ l.pesan }}</p>
          <details v-if="l.permintaan || l.respons">
            <summary>Isi permintaan dan respons (disamarkan)<template v-if="l.terpotong">, terpotong 16 KB</template></summary>
            <template v-if="l.permintaan">
              <h3>Permintaan</h3>
              <pre>{{ l.permintaan }}</pre>
            </template>
            <template v-if="l.respons">
              <h3>Respons</h3>
              <pre>{{ l.respons }}</pre>
            </template>
          </details>
        </li>
      </ul>
      <button v-if="kueri.hasNextPage.value" type="button" class="tombol muat" :disabled="kueri.isFetchingNextPage.value" @click="kueri.fetchNextPage()">
        {{ kueri.isFetchingNextPage.value ? 'Memuat…' : 'Muat lagi' }}
      </button>
    </div>
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
.log {
  display: grid;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.log > li {
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--border);
  min-width: 0;
}
.baris {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
code,
pre {
  font-family: var(--font-mono);
  font-size: 12px;
  overflow-wrap: anywhere;
}
.ket {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 12px;
}
.pesan {
  margin: 2px 0 0;
  font-size: 13px;
}
details {
  margin-top: var(--space-1);
  font-size: 12.5px;
}
details h3 {
  margin: var(--space-2) 0 2px;
  font-size: 12px;
}
pre {
  max-height: 240px;
  margin: 0;
  padding: var(--space-2);
  overflow: auto;
  white-space: pre-wrap;
  background: var(--bg);
  border-radius: var(--radius-input);
}
.muat {
  margin-top: var(--space-3);
}
</style>
