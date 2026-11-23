import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        // `tmp/` is the tool cache (Pint, PHPStan, Prettier, ESLint); in CI the package manager's own files live there as well.
        // Generated code is not linted: resources/js/design-system/ui/* by the shadcn-vue CLI, actions|routes|wayfinder by Wayfinder.
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'storage',
            'bootstrap/ssr',
            'tmp',
            'build',
            'resources/js/design-system/ui/*',
            'resources/js/actions/*',
            'resources/js/routes/*',
            'resources/js/wayfinder/*',
        ],
    },
    {
        files: ['resources/js/**/*.vue'],
        rules: {
            'vue/block-order': ['error', { order: ['script[setup]', 'template', 'style'] }],
            'vue/block-lang': ['error', { script: { lang: 'ts' } }],
            'vue/component-api-style': ['error', ['script-setup']],
            'vue/html-button-has-type': 'error',
            'vue/no-mutating-props': ['error', { shallowOnly: false }],
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        // Application code uses the design system through its public entry point (`@/design-system`) only.
        files: ['resources/js/**/*.{ts,vue}'],
        ignores: ['resources/js/design-system/**'],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    patterns: [
                        {
                            group: ['@/design-system/ui', '@/design-system/ui/**'],
                            message: 'Private design-system primitives are not application APIs. Import from "@/design-system" instead.',
                        },
                    ],
                },
            ],
        },
    },
    {
        // The design system is domain-independent: no pages, application components, routes or Inertia.
        files: ['resources/js/design-system/**/*.{ts,vue}'],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    patterns: [
                        {
                            group: ['@inertiajs/*', '@/pages/*', '@/components/*', '@/layouts/*', '@/actions/*', '@/routes', '@/routes/*', '@/wayfinder', '@/wayfinder/*'],
                            message: 'Keep application composition outside the design system and pass data or content through props and slots instead.',
                        },
                    ],
                },
            ],
        },
    },
    prettier,
);
