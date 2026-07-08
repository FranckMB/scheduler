import js from '@eslint/js'
import pluginQuery from '@tanstack/eslint-plugin-query'
import globals from 'globals'
import jsxA11y from 'eslint-plugin-jsx-a11y'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'
import { defineConfig, globalIgnores } from 'eslint/config'

// WCAG 2.2 AA guardrail. jsx-a11y's recommended set is enabled at 'warn' for now
// (non-blocking) so the norm is measured on every frontend change without breaking
// CI on the known violations (audit A11Y-01/03/05/06). PR2 fixes them, then this
// flips to 'error' (blocking). `eslint .` does not pass --max-warnings, so warnings
// surface in the log but keep the build green.
const jsxA11yWarn = Object.fromEntries(
  Object.keys(jsxA11y.flatConfigs.recommended.rules).map((rule) => [rule, 'warn']),
)

export default defineConfig([
  globalIgnores(['dist', 'storybook-static', 'src/shared/api/types.gen.ts']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
      pluginQuery.configs['flat/recommended'],
      jsxA11y.flatConfigs.recommended,
    ],
    languageOptions: {
      globals: globals.browser,
    },
    rules: {
      // WCAG guardrail at 'warn' (see note above) — flipped to 'error' in PR2.
      ...jsxA11yWarn,
      // shadcn/ui + router export constants (buttonVariants, router) alongside components.
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
      // Banned migration anti-patterns (frontend-strategy §3).
      'no-restricted-syntax': [
        'error',
        {
          selector: "MemberExpression[object.name='ReactDOM'][property.name='render']",
          message: 'ReactDOM.render is removed in React 19 — use createRoot().render().',
        },
        {
          selector: "Property[key.name='onSuccess'][parent.parent.callee.name='useQuery']",
          message: 'onSuccess was removed from useQuery in TanStack Query v5 — use useEffect on data, or select (useMutation.onSuccess is still valid).',
        },
      ],
    },
  },
])
