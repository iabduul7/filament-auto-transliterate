import * as esbuild from "esbuild";

const watch = process.argv.includes("--watch");

/**
 * Filament loads registered Js assets as a plain (non-module) <script>, so the
 * bundle must be an IIFE — not an ES module with a top-level `export`.
 */
const jsOptions = {
  entryPoints: ["resources/js/translation-overlay.js"],
  outfile: "resources/dist/filament-auto-transliterate.js",
  bundle: true,
  minify: true,
  format: "iife",
  target: "es2019",
};

const cssOptions = {
  entryPoints: ["resources/css/translation-overlay.css"],
  outfile: "resources/dist/filament-auto-transliterate.css",
  bundle: true,
  minify: true,
};

if (watch) {
  const jsCtx = await esbuild.context(jsOptions);
  const cssCtx = await esbuild.context(cssOptions);
  await Promise.all([jsCtx.watch(), cssCtx.watch()]);
  console.log("watching...");
} else {
  await Promise.all([esbuild.build(jsOptions), esbuild.build(cssOptions)]);
  console.log("built resources/dist");
}
