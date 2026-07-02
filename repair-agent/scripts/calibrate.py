#!/usr/bin/env python3
"""Utilitario pra descobrir coordenadas dos elementos do WhatsApp.

Uso:
    python scripts/calibrate.py <device_id>

Fluxo:
1. O script tira um screenshot atual do celular.
2. Voce abre o PNG no PC e ve as coordenadas do pixel do elemento desejado
   (qualquer visualizador de imagem mostra x/y no cursor).
3. Anota os valores em coordinates/<modelo>.yaml.

Alternativa mais tecnica: `adb shell dumpsys window` mostra bounds via UI Automator.
Ou instalar scrcpy no PC pra clicar direto na tela do celular.
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path


def main() -> int:
    if len(sys.argv) < 2:
        print("Uso: calibrate.py <device_id>")
        return 2

    device_id = sys.argv[1]
    out = Path(f"calibrate-{device_id}.png")

    subprocess.run(
        ["adb", "-s", device_id, "shell", "screencap", "-p", "/sdcard/calib.png"],
        check=True,
    )
    subprocess.run(
        ["adb", "-s", device_id, "pull", "/sdcard/calib.png", str(out)],
        check=True,
    )
    subprocess.run(
        ["adb", "-s", device_id, "shell", "rm", "/sdcard/calib.png"],
        check=True,
    )
    print(f"Screenshot salvo em {out}. Abra e anote as coordenadas dos elementos.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
