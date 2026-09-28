# Bangladesh location data — provenance

Bundled here so production never depends on GitHub at runtime. Only two files were
taken from the upstream project; everything else there (examples, `.js`/`.d.ts`
builds, README) is irrelevant to this importer.

- **Source**: https://github.com/sohan-99/bangladesh-location-data
- **Pinned commit**: `95b646aa863396eaeca81a25723cc814f24aa0c5`
- **Imported**: 2026-09-28
- **Files bundled**:
  - `locationBdDivisonsToUnionsEnglish.json` → `en.json` (460,124 bytes,
    sha256 `8a2767f18d8a56b7ef90767ad2bd5c5f8a5401eae12911995aaafd37c9b173c3`)
  - `locationBdDivisonsToUnionsBangla.json` → `bn.json` (482,775 bytes,
    sha256 `93bafc4c5adbe8f6c8e5f71dbab94d6de766e257a6bd8e9f1e3399d881287f58`)
- **License**: MIT (full text below), from upstream `LICENSE` at the same commit.

Both files are byte-identical copies of the upstream JSON — renamed only, never
edited. `App\Domain\Location\Actions\ImportBdLocations` reads them from this
directory; nothing in this application fetches them over the network.

## MIT License

```
MIT License

Copyright (c) 2025 Bangladesh Location Data Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
