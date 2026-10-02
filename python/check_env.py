"""Verify the local Python ML environment for Whisper / Hugging Face ASR."""

from __future__ import annotations

import shutil
import sys


def main() -> int:
    print(f"Python: {sys.version.split()[0]} ({sys.executable})")

    import torch
    import transformers
    import librosa  # noqa: F401
    import soundfile  # noqa: F401

    print(f"torch: {torch.__version__} | cuda={torch.cuda.is_available()}")
    print(f"transformers: {transformers.__version__}")

    ffmpeg = shutil.which("ffmpeg")
    print(f"ffmpeg: {ffmpeg or 'NOT FOUND'}")

    if not ffmpeg:
        print("WARNING: ffmpeg is required for most audio formats.")
        return 1

    print("OK: environment is ready for Whisper models.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
