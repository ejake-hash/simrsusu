<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import Dialog from '@/design-system/components/Dialog.vue'
import { pesanError } from '@/modules/akses/pengaturan'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { ambilTugas } from '../api'
import { KUNCI_NOTIF, LABEL_KANAL, LABEL_RIWAYAT, LABEL_TINGKAT, PIL_TINGKAT } from '../notifikasi'

/** Riwayat satu tugas (FR-53-07): dibuat, pengingat, teguran, eskalasi, selesai; penerima dan kanalnya. */
const props = defineProps<{ tugasId: string | null }>()
const buka = defineModel<boolean>('open', { required: true })

const kueri = useQuery({
  queryKey: computed(() => KUNCI_NOTIF.rinciTugas(props.tugasId ?? '')),
  queryFn: () => ambilTugas(props.tugasId!),
  enabled: computed(() => buka.value && !!props.tugasId),
})
const t = computed(() => kueri.data.value)
</script>

<template>
  <Dialog v-model:open="buka" :judul="t ? t.judul : 'Riwayat tugas'" lebar="lebar">
    <p v-if="kueri.isPending.value" class="ket" role="status">Memuat riwayat…</p>
    <p v-else-if="kueri.isError.value" class="catatan catatan-bahaya" role="alert">{{ pesanError(kueri.error.value) }}</p>
    <template v-else-if="t">
      <dl class="info">
        <div>
          <dt>Jenis</dt>
          <dd>{{ t.jenis.nama }}</dd>
        </div>
        <div>
          <dt>Objek</dt>
          <dd>{{ t.objek.label }}</dd>
        </div>
        <div>
          <dt>Pelaksana</dt>
          <dd>{{ t.pelaksana.nama }}</dd>
        </div>
        <div>
          <dt>Tenggat</dt>
          <dd>
            {{ formatTanggalWaktu(t.tenggat_pada) }}
            <span v-if="t.terlambat" class="pil pil-bahaya">Terlambat</span>
          </dd>
        </div>
        <div>
          <dt>Tingkat</dt>
          <dd><span class="pil" :class="PIL_TINGKAT[t.tingkat_saat_ini]">{{ LABEL_TINGKAT[t.tingkat_saat_ini] }}</span></dd>
        </div>
      </dl>
      <h3 class="judul-riwayat">Riwayat</h3>
      <ol class="linimasa">
        <li v-for="(r, i) in t.riwayat" :key="i">
          <span class="titik" :class="`titik-${r.jenis}`" aria-hidden="true" />
          <div>
            <b>{{ LABEL_RIWAYAT[r.jenis] ?? r.jenis }}</b>
            <time :datetime="r.terjadi_pada"> · {{ formatTanggalWaktu(r.terjadi_pada) }}</time>
            <p class="ket">
              <template v-if="r.penerima.length">Kepada {{ r.penerima.map((p) => p.nama).join(', ') }}</template>
              <template v-else-if="r.jenis !== 'selesai' && r.jenis !== 'batal'">Tanpa penerima</template>
              <template v-if="r.kanal.length"> · lewat {{ r.kanal.map((k) => LABEL_KANAL[k] ?? k).join(', ') }}</template>
            </p>
            <p v-if="r.catatan" class="ket">{{ r.catatan }}</p>
          </div>
        </li>
      </ol>
    </template>
    <template #aksi>
      <RouterLink v-if="t && t.status === 'terbuka'" :to="t.tautan" class="tombol tombol-utama" @click="buka = false">Buka layar penyelesaian</RouterLink>
      <button type="button" class="tombol" @click="buka = false">Tutup</button>
    </template>
  </Dialog>
</template>

<style scoped>
.info {
  display: grid;
  gap: var(--space-2);
  margin: 0 0 var(--space-4);
}
.info div {
  display: grid;
  grid-template-columns: 110px minmax(0, 1fr);
  gap: var(--space-2);
}
.info dt {
  color: var(--muted);
  font-size: 12.5px;
}
.info dd {
  margin: 0;
}
.judul-riwayat {
  margin: 0 0 var(--space-2);
  font-size: 14px;
}
.linimasa {
  display: grid;
  gap: var(--space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}
.linimasa li {
  display: grid;
  grid-template-columns: 12px minmax(0, 1fr);
  gap: var(--space-2);
}
.titik {
  width: 10px;
  height: 10px;
  margin-top: 5px;
  border-radius: 50%;
  background: var(--border);
}
.titik-pengingat {
  background: var(--info);
}
.titik-teguran {
  background: var(--warn);
}
.titik-eskalasi {
  background: var(--danger);
}
.titik-selesai {
  background: var(--success);
}
.ket {
  margin: 2px 0 0;
  color: var(--muted);
  font-size: 12.5px;
}
time {
  color: var(--muted);
  font-size: 12.5px;
}
</style>
