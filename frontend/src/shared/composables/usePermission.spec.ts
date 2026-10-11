import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import PermissionGate from '@/shared/components/PermissionGate.vue'
import { aturIzin, boleh, vCan } from './usePermission'

beforeEach(() => aturIzin([]))

describe('izin aksi (v-can, PermissionGate)', () => {
  it('tolak kecuali diberikan', () => {
    expect(boleh('master_data.akun.lihat')).toBe(false)
    aturIzin(['master_data.akun.lihat'])
    expect(boleh('master_data.akun.lihat')).toBe(true)
    expect(boleh('master_data.akun.ubah')).toBe(false)
  })

  it('v-can menyembunyikan elemen dan ikut berubah saat izin berubah', async () => {
    const w = mount({ template: `<button v-can="'master_data.akun.ubah'">Ubah</button>`, directives: { can: vCan } })
    expect(w.get('button').attributes('hidden')).toBeDefined()

    aturIzin(['master_data.akun.ubah'])
    await nextTick()
    expect(w.get('button').attributes('hidden')).toBeUndefined()

    aturIzin([])
    await nextTick()
    expect(w.get('button').attributes('hidden')).toBeDefined()
  })

  it('PermissionGate tidak merender isi tanpa izin; slot tolak opsional', async () => {
    const w = mount({
      components: { PermissionGate },
      template: `<PermissionGate izin="master_data.peran.ubah"><button>Simpan</button><template #tolak><p>Hanya lihat</p></template></PermissionGate>`,
    })
    expect(w.find('button').exists()).toBe(false)
    expect(w.text()).toBe('Hanya lihat')

    aturIzin(['master_data.peran.ubah'])
    await nextTick()
    expect(w.find('button').exists()).toBe(true)
  })
})
