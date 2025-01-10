let fs = require("fs");
let brotliSize = require("brotli-size");
let crypto = require("crypto");

build({
  entryPoints: [`js/index.js`],
  outfile: `dist/hotreload.js`,
  bundle: true,
  platform: "browser",
  define: { CDN: true },
});

build({
  format: "esm",
  entryPoints: [`js/index.js`],
  outfile: `dist/hotreload.esm.js`,
  sourcemap: "linked",
  bundle: true,
  platform: "node",
  define: { CDN: true },
});

let hash = crypto.randomBytes(4).toString("hex");

fs.writeFileSync(
  __dirname + "/../dist/manifest.json",
  `
{"/hotreload.js":"${hash}"}
`,
);

build({
  entryPoints: [`js/index.js`],
  outfile: `dist/hotreload.min.js`,
  sourcemap: "linked",
  bundle: true,
  minify: true,
  platform: "browser",
  define: { CDN: true },
}).then(() => {
  outputSize(`dist/hotreload.min.js`);
});

function build(options) {
  options.define || (options.define = {});

  options.define["process.env.NODE_ENV"] = process.argv.includes("--watch")
    ? `'production'`
    : `'development'`;

  return require("esbuild")
    .build({
      watch: process.argv.includes("--watch"),
      // external: ['Stimulus'],
      ...options,
    })
    .catch(() => process.exit(1));
}

function outputSize(file) {
  let size = bytesToSize(brotliSize.sync(fs.readFileSync(file)));

  console.log("\x1b[32m", `Bundle size: ${size}`);
}

function bytesToSize(bytes) {
  const sizes = ["Bytes", "KB", "MB", "GB", "TB"];
  if (bytes === 0) return "n/a";
  const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)), 10);
  if (i === 0) return `${bytes} ${sizes[i]}`;
  return `${(bytes / 1024 ** i).toFixed(1)} ${sizes[i]}`;
}
