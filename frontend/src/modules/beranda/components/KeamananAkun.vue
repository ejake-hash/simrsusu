<script setup lang="ts">
import { computed } from 'vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import type { Pengguna } from '@/modules/akses/api'
import { formatTanggalWaktu } from '@/shared/utils/waktu'

/**
 * Keamanan akun: waktu masuk sebelumnya (dan dari jaringan mana) agar pemilik akun bisa mengenali masuk yang bukan
 * dirinya, status MFA, dan siapa yang dihubungi.
 */
const props = defineProps<{ pengguna: Pengguna; unitAdmin: string; kontakAdmin: string | null }>()

const jaringan = computed(() => {
  const dari = props.pengguna.masuk_sebelumnya_dari_jaringan_rs
  return dari == null ? null : dari ? 'dari jaringan RS' : 'dari luar jaringan RS'
})
</script>

<template>
  <section class="kartu" aria-labelledby="judul-keamanan">
    <div class="kartu-kepala">
      <h2 id="judul-keamanan">Keamanan akun</h2>
    </div>
    <dl class="kartu-isi daftar">
      <div>
        <dt>Masuk sebelumnya</dt>
        <dd v-if="pengguna.masuk_sebelumnya_pada">
          {{ formatTanggalWaktu(pengguna.masuk_sebelumnya_pada) }}<template v-if="jaringan">, {{ jaringan }}</template>
        </dd>
        <!-- null = tidak ada catatan (kontrak v0.2.3): masuk pertama, atau akun lama sebelum fitur ini. -->
        <dd v-else>Belum ada catatan masuk sebelumnya — ini masuk pertama Anda, atau catatan baru mulai disimpan.</dd>
      </div>
      <div>
        <dt>Verifikasi dua langkah</dt>
        <dd>
          <span class="pil" :class="pengguna.mfa_aktif ? 'pil-ok' : 'pil-netral'">
            <Ikon :nama="pengguna.mfa_aktif ? 'centang' : 'info'" :ukuran="12" />{{ pengguna.mfa_aktif ? 'Aktif' : 'Belum aktif' }}
          </span>
        </dd>
      </div>
    </dl>
    <p class="kartu-isi bantuan">
      Bukan Anda yang masuk? Hubungi {{ unitAdmin }}<template v-if="kontakAdmin"> di {{ kontakAdmin }}</template> sekarang.
    </p>
  </section>
</template>

<style scoped>
.daftar {
  display: grid;
  gap: var(--space-3);
  margin: 0;
}
dt {
  font-size: 12px;
  color: var(--muted);
}
dd {
  margin: 2px 0 0;
  font-size: 13.5px;
}
.bantuan {
  margin: 0;
  padding-top: 0;
  font-size: 12.5px;
  color: var(--muted);
}
</style>
