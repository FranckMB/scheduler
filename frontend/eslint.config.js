import js from '@eslint/js'
import pluginQuery from '@tanstack/eslint-plugin-query'
import globals from 'globals'
import jsxA11y from 'eslint-plugin-jsx-a11y'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'
import { defineConfig, globalIgnores } from 'eslint/config'

// WCAG 2.2 AA guardrail. jsx-a11y's recommended set runs on every frontend change.
// A single knob — A11Y_LEVEL — drives whether violations warn or block the build.
// PR2 fixed the known audit violations (A11Y-01/03/05/06), so it is now 'error'
// (blocking). Flip back to 'warn' only to temporarily unblock a large refactor.
const A11Y_LEVEL = 'error'

// We ONLY re-severity the rules recommended actually turns ON, and we PRESERVE each
// rule's tuned options — blindly remapping every key would (a) force on the rules
// recommended deliberately sets to 'off' (e.g. the deprecated label-has-for, which
// double-flags correctly htmlFor/id-associated labels) and (b) drop the option
// objects on tuned rules.
const jsxA11yRules = Object.fromEntries(
  Object.entries(jsxA11y.flatConfigs.recommended.rules).flatMap(([rule, config]) => {
    const severity = Array.isArray(config) ? config[0] : config
    if (severity === 'off' || severity === 0) {
      return [] // keep recommended's disabled rules disabled
    }
    const options = Array.isArray(config) ? config.slice(1) : []
    return [[rule, [A11Y_LEVEL, ...options]]]
  }),
)

export default defineConfig([
  globalIgnores(['dist', 'storybook-static']),
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
      // WCAG guardrail (severity via A11Y_LEVEL above).
      ...jsxA11yRules,
      // Our design-system controls are custom components, not native <input>/<select>;
      // tell label-has-associated-control so nested `<label>…<Input/></label>` is seen
      // as correctly associated instead of a false "label without control".
      'jsx-a11y/label-has-associated-control': [A11Y_LEVEL, { controlComponents: ['Input', 'Select', 'TeamSelect'] }],
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
