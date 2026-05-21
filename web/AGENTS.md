# MyInvoice.cz Frontend Development

## Vue 3 + TypeScript + Tailwind CSS 4

### Tech Stack

- **Vue 3.5** with Composition API and `<script setup>`
- **TypeScript 5.7** for type safety
- **Tailwind CSS 4** for styling
- **Pinia 3** for state management
- **Vue Router 5** for navigation
- **VueUse 14** for utilities
- **Axios 1.16** for API calls

### Project Structure

```
web/src/
├── api/                # Axios client + endpoint wrappers
│   ├── axios.ts        # Axios instance + interceptors
│   ├── invoices.ts     # Invoice API calls
│   ├── clients.ts      # Client API calls
│   └── index.ts        # Export all API functions
├── components/          # Reusable components
│   └── ui/             # Base UI components
├── composables/        # Vue composables
├── pages/             # Route pages
│   ├── Login.vue
│   ├── Dashboard.vue
│   ├── invoices/       # Invoice pages
│   ├── clients/       # Client pages
│   └── settings/      # Settings pages
├── router/index.ts    # Vue Router config
├── stores/            # Pinia stores
│   ├── auth.ts        # Auth state
│   ├── invoices.ts    # Invoice state
│   └── supplier.ts    # Current supplier
└── styles/            # CSS and Tailwind config
```

### Key Patterns

#### API Calls

Always use the centralized API client with proper error handling:

```typescript
// Use existing API wrapper
import { invoicesApi } from '@/api'

// In stores or composables
const { data, pending, error } = await useFetch('/api/invoices')
```

#### Multi-Supplier Scoping

The `supplier` store automatically adds `X-Supplier-Id` header to all requests:

```typescript
const supplierStore = useSupplierStore()
// All API calls automatically scoped
```

#### State Management

Pinia stores handle all state:

- `useAuthStore` — user session, login/logout
- `useSupplierStore` — current supplier, switcher
- `useInvoicesStore` — invoice list, filters, pagination
- `useClientsStore` — client list
- `useCodebookStore` — countries, currencies, VAT rates

### Build Commands

```bash
cd web
pnpm install
pnpm dev      # Vite dev server
pnpm build    # Production build
pnpm preview  # Preview production build
```

### Tailwind CSS 4

Uses CSS-first configuration with `@theme` directive:

```css
@theme {
  --color-primary-600: #059669;
  --color-neutral-900: #18181B;
  /* ... */
}
```

### i18n

Vue-i18n for CZ/EN localization:

```typescript
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
```

Translation files: `web/src/i18n/`
