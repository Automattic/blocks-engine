export default [
    { ignores: ['**/vendor/**', '**/node_modules/**', '**/var/**'] },
    {
        files: ['**/*.js', '**/*.mjs'],
        languageOptions: { ecmaVersion: 'latest', sourceType: 'module' },
        rules: {
            'constructor-super': 'error',
            'no-dupe-args': 'error',
            'no-dupe-class-members': 'error',
            'no-duplicate-case': 'error',
            'no-func-assign': 'error',
            'no-import-assign': 'error',
            'no-unreachable': 'error',
            'valid-typeof': 'error',
        },
    },
];
