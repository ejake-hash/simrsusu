<script setup lang="ts">
import { computed } from 'vue'
import { turunanTema } from '@/app/tema'
import Ikon from '@/design-system/ikon/Ikon.vue'

/**
 * Pratinjau identitas baru sebelum disimpan (tiket 1.8a fe 1): potongan halaman masuk dan sidebar memakai warna dari
 * formulir. Token tema dipasang di wadah ini saja (variabel CSS menurun), tidak mengubah layar yang sedang dipakai.
 */
const props = defineProps<{ nama: string; logo: string | null; brand: string; accent: string; unitAdmin: string }>()
const gaya = computed(() => turunanTema(props.brand, props.accent) ?? {})
</script>

<template>
  <div class="pratinjau" :style="gaya" aria-label="Pratinjau identitas" role="img">
    <div class="masuk">
      <div class="atas">
        <img src="/logo/karsitara.png" alt="" width="96" height="17" />
        <div v-if="nama" class="kartu-rs">
          <span>{{ nama }}</span>
          <img v-if="logo" :src="logo" alt="" width="22" height="22" />
        </div>
      </div>
      <p class="label-atas">SIMRS · Rumah Sakit Pendidikan</p>
      <p class="judul">Masuk ke akun Anda</p>
      <span class="isian" />
      <span class="isian" />
      <span class="tombol">Masuk</span>
      <p class="bantuan">Hubungi {{ unitAdmin }}</p>
    </div>
    <div class="sidebar">
      <span class="item"><Ikon nama="beranda" :ukuran="14" />Beranda</span>
      <span class="item aktif"><Ikon nama="pengaturan" :ukuran="14" />Pengaturan Sistem</span>
      <span class="sub">Profil rumah sakit</span>
      <span class="aksen">Aksen</span>
    </div>
  </div>
</template>

<style scoped>
.pratinjau {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: var(--space-3);
  font-size: 11px;
}
.masuk,
.sidebar {
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
}
.atas {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.kartu-rs {
  display: flex;
  align-items: center;
  gap: 6px;
  max-width: 60%;
  color: var(--muted);
  font-size: 9px;
  text-align: right;
}
.kartu-rs img {
  object-fit: contain;
}
.label-atas {
  margin: 0;
  color: var(--brand-600);
  font-size: 8px;
  font-weight: var(--font-weight-semibold);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.judul {
  margin: 2px 0 var(--space-2);
  font-size: 13px;
  font-weight: var(--font-weight-bold);
}
.isian {
  display: block;
  height: 18px;
  margin-bottom: 6px;
  border: 1px solid var(--border-input);
  border-radius: 4px;
}
.tombol {
  display: grid;
  place-items: center;
  height: 20px;
  margin-top: var(--space-2);
  border-radius: 4px;
  background: var(--brand-600);
  color: var(--surface);
  font-weight: var(--font-weight-semibold);
}
.bantuan {
  margin: 6px 0 0;
  color: var(--muted);
  font-size: 9px;
  text-align: center;
}
.sidebar {
  display: grid;
  align-content: start;
  gap: 4px;
}
.item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 6px;
  border-radius: 5px;
  color: var(--muted);
}
.item.aktif {
  background: var(--brand-50);
  color: var(--brand-800);
  font-weight: var(--font-weight-semibold);
}
.sub {
  padding: 3px 6px 3px 26px;
  border-radius: 4px;
  background: var(--brand-25);
  color: var(--brand-800);
}
.aksen {
  justify-self: start;
  margin-top: var(--space-2);
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--accent-bg);
  color: var(--accent-ink);
  box-shadow: inset 0 0 0 1px var(--accent);
}
</style>
