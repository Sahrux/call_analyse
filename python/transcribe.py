#!/usr/bin/env python3
"""Transcribe an audio file with Hugging Face openai/whisper-large-v3.

Usage:
  python transcribe.py /path/to/audio.mp3 --language az --model openai/whisper-large-v3

Prints a single JSON object to stdout:
  {"text": "...", "segments": [{"start": 0.0, "end": 1.2, "text": "..."}], "duration_seconds": 12}
"""
 
from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
from pathlib import Path
from typing import Any

LANGUAGE_MAP = {
    "az": "turkish",  # Whisper has no Azerbaijani; Turkish is the closest official code
    "ru": "russian",
    "en": "english",
}


def bundled_ffmpeg() -> str | None:
    name = "ffmpeg.exe" if os.name == "nt" else "ffmpeg"
    candidate = Path(__file__).resolve().parent / "bin" / name
    return str(candidate) if candidate.is_file() else None


def ensure_ffmpeg(ffmpeg_path: str | None) -> str:
    """Make ffmpeg discoverable on PATH and return the resolved executable path."""
    if os.name == "nt" and not os.environ.get("PATHEXT"):
        os.environ["PATHEXT"] = ".COM;.EXE;.BAT;.CMD;.VBS;.JS;.WS;.MSC"

    candidates = [
        ffmpeg_path,
        os.environ.get("WHISPER_FFMPEG"),
        bundled_ffmpeg(),
        shutil.which("ffmpeg"),
    ]

    for candidate in candidates:
        if not candidate:
            continue

        path = os.path.abspath(candidate)
        if not os.path.isfile(path):
            continue

        bin_dir = os.path.dirname(path)
        os.environ["PATH"] = bin_dir + os.pathsep + os.environ.get("PATH", "")
        os.environ["FFMPEG_BINARY"] = path
        os.environ["WHISPER_FFMPEG"] = path

        resolved = shutil.which("ffmpeg")
        if resolved:
            return resolved

        # PATH update failed (rare Windows env issues) — still return absolute path
        return path

    raise RuntimeError(
        "ffmpeg was not found but is required to load audio files. "
        "Install ffmpeg, place it at python/bin/ffmpeg.exe, or set WHISPER_FFMPEG."
    )


def build_pipeline(model: str):
    import torch
    from transformers import pipeline

    device = 0 if torch.cuda.is_available() else -1
    dtype = torch.float16 if torch.cuda.is_available() else torch.float32

    return pipeline(
        "automatic-speech-recognition",
        model=model,
        device=device,
        dtype=dtype,
    )


def normalize_segments(chunks: list[dict[str, Any]] | None) -> list[dict[str, Any]]:
    segments: list[dict[str, Any]] = []

    for chunk in chunks or []:
        timestamp = chunk.get("timestamp") or (None, None)
        start, end = timestamp if isinstance(timestamp, (list, tuple)) else (None, None)
        text = (chunk.get("text") or "").strip()

        if not text:
            continue

        segments.append(
            {
                "start": float(start) if start is not None else None,
                "end": float(end) if end is not None else None,
                "text": text,
            }
        )

    return segments


def duration_from_segments(segments: list[dict[str, Any]]) -> int | None:
    ends = [s["end"] for s in segments if s.get("end") is not None]
    if not ends:
        return None

    return int(max(ends))


def main() -> int:
    parser = argparse.ArgumentParser(description="Whisper transcription for Call Analyse")
    parser.add_argument("audio_path", help="Absolute path to the audio file")
    parser.add_argument("--language", default=None, help="App language code: az, ru, en")
    parser.add_argument(
        "--model",
        default="openai/whisper-large-v3",
        help="Hugging Face model id",
    )
    parser.add_argument(
        "--ffmpeg",
        default=None,
        help="Absolute path to the ffmpeg executable",
    )
    args = parser.parse_args()

    generate_kwargs: dict[str, Any] = {"task": "transcribe"}
    whisper_language = LANGUAGE_MAP.get((args.language or "").lower())
    if whisper_language:
        generate_kwargs["language"] = whisper_language

    try:
        ensure_ffmpeg(args.ffmpeg)
        pipe = build_pipeline(args.model)
        result = pipe(
            args.audio_path,
            return_timestamps=True,
            generate_kwargs=generate_kwargs,
        )
    except Exception as exc:  # noqa: BLE001 - surface any model/runtime error as JSON
        print(json.dumps({"error": str(exc)}), file=sys.stderr)
        return 1

    text = (result.get("text") or "").strip()
    segments = normalize_segments(result.get("chunks"))

    payload = {
        "text": text,
        "segments": segments,
        "duration_seconds": duration_from_segments(segments),
        "model": args.model,
        "whisper_language": whisper_language,
    }

    print(json.dumps(payload, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
