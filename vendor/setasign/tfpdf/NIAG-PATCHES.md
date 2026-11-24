# Local compatibility fixes for tFPDF 1.33

- Resolve each TTF path from the current installation after reading cached metrics. Upstream metrics files may contain absolute paths from another computer.
- Honor FPDF_CACHE_MODE=1 at both font-cache write sites so the application never writes runtime data into system/.
- The bundled DejaVu metrics use __DIR__ instead of a developer machine path.

Preserve these fixes when replacing/upgrading this vendored library. The local project regression test tests/relocated_install.py verifies FTP relocation, legacy absolute cache paths, missing caches, and a missing font during test completion.
