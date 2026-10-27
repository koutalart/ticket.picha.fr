// Writes .br and .gz copies of the built client assets, served as is by sirv (no compression per request).
import fs from "node:fs/promises";
import path from "node:path";
import zlib from "node:zlib";
import {promisify} from "node:util";
import {fileURLToPath} from "node:url";

const brotli = promisify(zlib.brotliCompress);
const gzip = promisify(zlib.gzip);

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), "../dist/client");
const COMPRESSIBLE = /\.(js|mjs|css|json|svg|txt|xml|webmanifest)$/;
const MIN_SIZE = 1024;

const walk = async (dir) => (await Promise.all(
    (await fs.readdir(dir, {withFileTypes: true})).map((entry) => {
        const full = path.join(dir, entry.name);
        return entry.isDirectory() ? walk(full) : [full];
    })
)).flat();

const files = (await walk(root)).filter((file) => COMPRESSIBLE.test(file));
let before = 0;
let after = 0;

for (const file of files) {
    const content = await fs.readFile(file);
    if (content.length < MIN_SIZE) {
        continue;
    }
    const [br, gz] = await Promise.all([
        brotli(content, {
            params: {
                [zlib.constants.BROTLI_PARAM_QUALITY]: 11,
                [zlib.constants.BROTLI_PARAM_SIZE_HINT]: content.length,
            },
        }),
        gzip(content, {level: 9}),
    ]);
    await Promise.all([fs.writeFile(`${file}.br`, br), fs.writeFile(`${file}.gz`, gz)]);
    before += content.length;
    after += br.length;
}

console.info(`precompress: ${files.length} files, ${Math.round(before / 1024)} KB -> ${Math.round(after / 1024)} KB (brotli)`);
