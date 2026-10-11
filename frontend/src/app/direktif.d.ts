import type { vCan } from '@/shared/composables/usePermission'

// Direktif global yang dipasang di main.ts.
declare module 'vue' {
  interface GlobalDirectives {
    vCan: typeof vCan
  }
}
