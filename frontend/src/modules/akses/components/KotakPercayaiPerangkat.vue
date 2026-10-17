<script setup lang="ts">
import { useId } from 'vue'
import type { PercayaiPerangkat } from '../alurMasuk'

/**
 * "Percayai perangkat ini" di langkah MFA (tiket 1.13, kontrak v0.16.0). Hanya tampil bila server menawarkan (dari
 * jaringan RS dan masa percaya akun > 0). Bawaan tidak dicentang. Token perangkat dipasang server sebagai cookie
 * HttpOnly; klien hanya mengirim pilihan ini.
 */
defineProps<{ tawaran: PercayaiPerangkat }>()
const percayai = defineModel<boolean>({ required: true })
const id = `percayai-${useId()}`
</script>

<template>
  <div v-if="tawaran.tersedia" class="percayai">
    <label class="centang" :for="id">
      <input :id="id" v-model="percayai" type="checkbox" name="percayai_perangkat" :aria-describedby="`${id}-ket`" />
      <span>Percayai perangkat ini selama {{ tawaran.hari }} hari</span>
    </label>
    <p :id="`${id}-ket`" class="ket">
      Masuk berikutnya dari peramban ini di jaringan RS tidak meminta kode. Jangan dicentang di komputer bersama. Dari luar
      jaringan RS kode tetap diminta, dan perangkat bisa dicabut kapan saja dari menu akun.
    </p>
  </div>
</template>

<style scoped>
.percayai {
  margin: var(--space-4) 0;
}
.centang {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  font-size: 13px;
  cursor: pointer;
}
.centang input {
  flex: none;
  width: 18px;
  height: 18px;
  margin: 1px 0 0;
  accent-color: var(--brand-600);
}
.ket {
  margin: 4px 0 0 26px;
  color: var(--muted);
  font-size: 12px;
}
</style>
