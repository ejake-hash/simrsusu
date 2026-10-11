import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import PermissionGate from '@/shared/components/PermissionGate.vue'
import { aturIzin, boleh, vCan } from './usePermission'

beforeEach(() => aturIzin([]))

describe('izin aksi (v-can, PermissionGate)', () => {
  it('tolak kecuali diberikan', () => {
    expect(boleh('pengaturan.akun.lihat')).toBe(false)
    aturIzin(['pengaturan.akun.lihat'])
    expect(boleh('pengaturan.akun.lihat')).toBe(true)
    expect(boleh('pengaturan.akun.perbaiki')).toBe(false)
  })

  it('v-can menyembunyikan elemen dan ikut berubah saat izin berubah', async () => {
    const w = mount({ template: `<button v-can="'pengaturan.akun.perbaiki'">Ubah</button>`, directives: { can: vCan } })
    expect(w.get('button').attributes('hidden')).toBeDefined()

    aturIzin(['pengaturan.akun.perbaiki'])
    await nextTick()
    expect(w.get('button').attributes('hidden')).toBeUndefined()

    aturIzin([])
    await nextTick()
    expect(w.get('button').attributes('hidden')).toBeDefined()
  })

  it('PermissionGate tidak merender isi tanpa izin; slot tolak opsional', async () => {
    const w = mount({
      components: { PermissionGate },
      template: `<PermissionGate izin="pengaturan.peran.perbaiki"><button>Simpan</button><template #tolak><p>Hanya lihat</p></template></PermissionGate>`,
    })
    expect(w.find('button').exists()).toBe(false)
    expect(w.text()).toBe('Hanya lihat')

    aturIzin(['pengaturan.peran.perbaiki'])
    await nextTick()
    expect(w.find('button').exists()).toBe(true)
  })
})
