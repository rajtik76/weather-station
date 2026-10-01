import { defineConfig, lazyPlugins } from "vite-plus";
import laravel from "laravel-vite-plugin";
import { bunny } from "laravel-vite-plugin/fonts";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    staged: {
        "*": "vp check --fix",
    },
    fmt: {},
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
                bunny("Red Hat Display", {
                    weights: [500, 600, 700],
                }),
                bunny("Red Hat Text", {
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
