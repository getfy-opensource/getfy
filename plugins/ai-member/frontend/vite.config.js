import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';
import { writeFileSync } from 'fs';

const distDir = resolve(__dirname, '../dist');

const UI_MANIFEST = {
    exports: ['IntegrationsSidebar', 'ProductAgentPanel', 'FloatingChatWidget', 'SettingsPage'],
    entry: 'plugin-ui.js',
};

function emitUiManifest() {
    return {
        name: 'emit-ui-manifest',
        closeBundle() {
            writeFileSync(
                resolve(distDir, 'ui.manifest.json'),
                `${JSON.stringify(UI_MANIFEST, null, 2)}\n`,
                'utf8',
            );
        },
    };
}

export default defineConfig({
    plugins: [vue(), emitUiManifest()],
    build: {
        lib: {
            entry: resolve(__dirname, 'src/main.js'),
            formats: ['es'],
            fileName: () => 'plugin-ui.js',
        },
        outDir: distDir,
        emptyOutDir: true,
        rollupOptions: {
            external: ['vue'],
            output: {
                assetFileNames: 'plugin-ui.[ext]',
            },
        },
    },
});
