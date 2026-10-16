# dds2rgba

Decodes FS25 store-icon DDS files (DX10 BC7 / BC1–BC3) to raw RGBA8.

```text
gcc -O2 -o ../../bin/dds2rgba main.c
```

`DdsToPng` calls `bin/dds2rgba` for DXGI formats 98/99 (BC7). The VPS installer builds this binary when `gcc` is available (`build-essential`).

Uses [bcdec](https://github.com/iOrange/bcdec) (MIT / Unlicense).
