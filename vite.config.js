import { defineConfig, lazyPlugins } from "vite-plus";
import laravel from "laravel-vite-plugin";
import { bunny } from "laravel-vite-plugin/fonts";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    staged: {
        "*": "vp check --fix",
    },
    fmt: {},
    test: {
        include: ["resources/js/**/*.test.js"],
    },
    lint: {
        jsPlugins: [{ name: "vite-plus", specifier: "vite-plus/oxlint-plugin" }],
        rules: { "vite-plus/prefer-vite-plus-imports": "error" },
        options: { typeAware: true, typeCheck: true },
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
            fonts: [
                bunny("Instrument Serif", {
                    weights: [400],
                    styles: ["normal", "italic"],
                }),
                bunny("Hanken Grotesk", {
                    weights: [400, 500, 600],
                }),
                bunny("Red Hat Mono", {
                    weights: [400, 500],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
