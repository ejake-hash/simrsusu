<script setup lang="ts">
import { computed, ref } from 'vue'
import Sakelar from '@/design-system/components/Sakelar.vue'
import Ikon from '@/design-system/ikon/Ikon.vue'
import { boleh } from '@/shared/composables/usePermission'
import { formatTanggalWaktu } from '@/shared/utils/waktu'
import { bolehUbahAkun } from '../../pengaturan'
import type { AkunRinci } from '../../tipePengaturan'
import DialogMfa from './DialogMfa.vue'

/**
 * MFA akun (PRD 10.3.2, 9.3): status, sumber kewajiban (yang tidak bisa dimatikan diberi gembok dan alasannya),
 * sakelar wajib MFA tingkat akun, dan reset MFA. Sakelar tidak langsung berubah: dialog alasan dulu.
 */
const props = defineProps<{ akun: AkunRinci }>()
const emit = defineEmits<{ berubah: [pesan: string] }>()

const mfa = computed(() => props.akun.mfa)
const bolehUbah = computed(() => bolehUbahAkun(props.akun) && boleh('pengaturan.akun.perbaiki'))
const ALASAN_TERKUNCI: Record<string, string> = {
  superadmin: 'Superadmin selalu wajib MFA.',
  izin_sensitif: 'Pemegang izin sensitif selalu wajib MFA.',
  peran: 'Peran ini memuat izin sensitif.',
}
const CARA_MEMATIKAN: Record<string, string> = { peran: 'Diatur di Peran & izin.', akun: 'Diatur dengan sakelar di bawah.' }

const dialog = ref(false)
const mode = ref<'wajib' | 'reset'>('wajib')
const wajibBaru = ref(false)

// Sakelar mencerminkan server; perubahan hanya lewat dialog.
const sakelar = computed({
  get: () => mfa.value.wajib_akun,
  set: (v: boolean) => {
    mode.value = 'wajib'
    wajibBaru.value = v
    dialog.value = true
  },
})
function bukaReset() {
  mode.value = 'reset'
  dialog.value = true
}
function selesai() {
  emit('berubah', mode.value === 'reset' ? `MFA ${props.akun.nama} direset. Sesi akun diputus.` : `Sakelar wajib MFA ${props.akun.nama} ${wajibBaru.value ? 'dinyalakan' : 'dimatikan'}.`)
}
</script>

<template>
  <div>
    <div class="kepala-tab">
      <h3>Autentikasi dua langkah (MFA)</h3>
    </div>
    <dl class="status">
      <div>
        <dt>Status</dt>
        <dd>
          <span class="pil" :class="mfa.aktif ? 'pil-ok' : 'pil-peringatan'">
            <Ikon :nama="mfa.aktif ? 'centang' : 'peringatan'" :ukuran="12" />{{ mfa.aktif ? 'Terdaftar' : 'Belum terdaftar' }}
          </span>
          <span v-if="mfa.aktif_pada" class="waktu">sejak {{ formatTanggalWaktu(mfa.aktif_pada) }}</span>
        </dd>
      </div>
    </dl>

    <p class="catatan catatan-netral"><Ikon nama="info" :ukuran="16" /><span>Dari luar jaringan RS, MFA selalu wajib.</span></p>

    <h4>Di dalam jaringan RS, MFA wajib karena</h4>
    <ul v-if="mfa.sumber_wajib.length" class="sumber">
      <li v-for="s in mfa.sumber_wajib" :key="`${s.jenis}-${s.label}`">
        <Ikon v-if="!s.dapat_dimatikan" nama="gembok" :ukuran="14" />
        <span>
          {{ s.label }}
          <small v-if="!s.dapat_dimatikan">Tidak bisa dimatikan. {{ ALASAN_TERKUNCI[s.jenis] ?? '' }}</small>
          <small v-else-if="CARA_MEMATIKAN[s.jenis]">{{ CARA_MEMATIKAN[s.jenis] }}</small>
        </span>
      </li>
    </ul>
    <p v-else class="kosong">Tidak ada: di dalam jaringan RS akun ini masuk tanpa MFA.</p>

    <div v-if="bolehUbah" class="kendali">
      <Sakelar v-model="sakelar" label="Wajib MFA tingkat akun" />
      <div>
        <button type="button" class="tombol tombol-bahaya" :disabled="!mfa.dapat_direset" :aria-describedby="mfa.dapat_direset ? undefined : 'ket-reset'" @click="bukaReset">Reset MFA</button>
        <p v-if="!mfa.dapat_direset" id="ket-reset" class="kosong">MFA belum terdaftar, tidak ada yang direset.</p>
      </div>
    </div>

    <DialogMfa v-model:open="dialog" :mode="mode" :akun-id="akun.id" :nama-akun="akun.nama" :wajib-baru="wajibBaru" :mfa-aktif="mfa.aktif" @selesai="selesai" />
  </div>
</template>

<style scoped src="./tab-akun.css"></style>
<style scoped>
.status {
  margin: 0;
}
.status div {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}
dt {
  font-size: 13px;
  color: var(--muted);
}
dd {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin: 0;
}
.waktu {
  font-size: 12.5px;
  color: var(--muted);
}
h4 {
  margin: var(--space-4) 0 6px;
  font-size: 13px;
}
.sumber {
  list-style: none;
  display: grid;
  gap: 6px;
  margin: 0;
  padding: 0;
  font-size: 13px;
}
.sumber li {
  display: flex;
  gap: var(--space-2);
}
.sumber svg {
  flex: none;
  margin-top: 2px;
  color: var(--muted);
}
.sumber li:not(:has(svg)) {
  padding-left: 22px;
}
.sumber small {
  display: block;
  color: var(--muted);
}
.kendali {
  display: grid;
  gap: var(--space-4);
  margin-top: var(--space-4);
  padding-top: var(--space-4);
  border-top: 1px solid var(--border);
}
.kendali .kosong {
  margin-top: 4px;
  font-size: 12px;
}
</style>
