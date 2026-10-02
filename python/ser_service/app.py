"""
FastAPI inference service for the acoustic wav2vec2 + LoRA SER model.

Loads the model once at startup from MODEL_DIR (Colab final_model/ export).
"""

from __future__ import annotations

import json
import logging
import os
import subprocess
import tempfile
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

import imageio_ffmpeg
import numpy as np
import soundfile as sf
import torch
import torch.nn as nn
import torchaudio
from fastapi import FastAPI, File, HTTPException, UploadFile
from peft import LoraConfig, get_peft_model
from pydub import AudioSegment
from transformers import Wav2Vec2FeatureExtractor, Wav2Vec2Model

# Use the ffmpeg binary bundled with imageio-ffmpeg (no system FFmpeg / torchcodec).
_FFMPEG_EXE = imageio_ffmpeg.get_ffmpeg_exe()
AudioSegment.converter = _FFMPEG_EXE

logger = logging.getLogger("ser_service")
logging.basicConfig(level=logging.INFO)

DEFAULT_SCORE_FIELDS = [
    "courtesy_score",
    "rudeness_score",
    "aggression_score",
    "emotional_tone_score",
]
# Newer checkpoints drop aggression and emit the three scores Laravel stores.
THREE_SCORE_FIELDS = [
    "courtesy_score",
    "rudeness_score",
    "emotional_tone_score",
]
BASE_MODEL_NAME = "facebook/wav2vec2-large-xlsr-53"
TARGET_SR = 16_000
TARGET_SECONDS = 8
TARGET_SAMPLES = TARGET_SR * TARGET_SECONDS

STATE_DICT_CANDIDATES = (
    "ser_model_state_dict.pt",
    "best_model.pt",
)

# Extension → (ffmpeg demuxer format, decoder codec). codec skips pydub's ffprobe call.
_AUDIO_FORMAT_CODEC: dict[str, tuple[str, str]] = {
    ".mp3": ("mp3", "mp3"),
    ".wav": ("wav", "pcm_s16le"),
    ".m4a": ("m4a", "aac"),
    ".aac": ("aac", "aac"),
    ".ogg": ("ogg", "libvorbis"),
    ".webm": ("webm", "libopus"),
    ".flac": ("flac", "flac"),
}

class SerRegressionModel(nn.Module):
    """Wav2Vec2-large-xlsr-53 + LoRA + mean-pool + Sequential sigmoid head."""

    def __init__(self, num_labels: int = 4) -> None:
        super().__init__()
        base = Wav2Vec2Model.from_pretrained(BASE_MODEL_NAME)
        base.feature_extractor._freeze_parameters()

        lora_config = LoraConfig(
            r=8,
            lora_alpha=16,
            target_modules=["q_proj", "v_proj"],
            lora_dropout=0.1,
            bias="none",
        )
        # Attribute name must be `wav2vec2` to match Colab state_dict prefixes.
        self.wav2vec2 = get_peft_model(base, lora_config)

        hidden_size = base.config.hidden_size
        self.regressor = nn.Sequential(
            nn.Linear(hidden_size, 256),
            nn.ReLU(),
            nn.Dropout(0.2),
            nn.Linear(256, num_labels),
            nn.Sigmoid(),
        )

    def forward(
        self,
        input_values: torch.Tensor,
        attention_mask: torch.Tensor | None = None,
    ) -> torch.Tensor:
        outputs = self.wav2vec2(
            input_values=input_values,
            attention_mask=attention_mask,
        )
        # Mean-pool over time dimension (matches training).
        pooled = outputs.last_hidden_state.mean(dim=1)
        return self.regressor(pooled)


def _resolve_model_dir() -> Path:
    raw = os.environ.get("MODEL_DIR", "./ser_model/final_model")
    return Path(raw).expanduser().resolve()


def _resolve_model_version() -> str:
    env_version = os.environ.get("MODEL_VERSION", "").strip()
    if env_version:
        return env_version
    return "unknown"


def _checkpoint_label_count(state_dict: dict[str, torch.Tensor]) -> int | None:
    weight = state_dict.get("regressor.3.weight")
    if weight is None or not hasattr(weight, "shape") or len(weight.shape) < 1:
        return None
    return int(weight.shape[0])


def _fields_for_label_count(label_count: int) -> list[str]:
    if label_count == len(THREE_SCORE_FIELDS):
        return list(THREE_SCORE_FIELDS)
    if label_count == len(DEFAULT_SCORE_FIELDS):
        return list(DEFAULT_SCORE_FIELDS)
    raise ValueError(f"Unsupported checkpoint head count: {label_count}")


def _load_score_fields(model_dir: Path) -> list[str]:
    path = model_dir / "score_fields.json"
    if not path.is_file():
        logger.warning("score_fields.json missing; using defaults %s", DEFAULT_SCORE_FIELDS)
        return list(DEFAULT_SCORE_FIELDS)

    data = json.loads(path.read_text(encoding="utf-8"))
    if isinstance(data, dict) and "fields" in data:
        data = data["fields"]
    if not isinstance(data, list) or not data:
        return list(DEFAULT_SCORE_FIELDS)
    return [str(item) for item in data]


def _align_score_fields(score_fields: list[str], label_count: int | None) -> list[str]:
    if label_count is None or len(score_fields) == label_count:
        return score_fields

    aligned = _fields_for_label_count(label_count)
    logger.warning(
        "score field count %d does not match checkpoint head count %d; using %s",
        len(score_fields),
        label_count,
        aligned,
    )
    return aligned


def _find_state_dict_path(model_dir: Path) -> Path:
    for name in STATE_DICT_CANDIDATES:
        candidate = model_dir / name
        if candidate.is_file():
            return candidate
    raise FileNotFoundError(
        f"No state dict found in {model_dir}. Looked for: {', '.join(STATE_DICT_CANDIDATES)}"
    )


def _load_state_dict(path: Path) -> dict[str, torch.Tensor]:
    checkpoint = torch.load(path, map_location="cpu", weights_only=False)
    if isinstance(checkpoint, dict) and "state_dict" in checkpoint:
        return checkpoint["state_dict"]
    if isinstance(checkpoint, dict) and "model_state_dict" in checkpoint:
        return checkpoint["model_state_dict"]
    if isinstance(checkpoint, dict) and all(isinstance(k, str) for k in checkpoint.keys()):
        return checkpoint
    raise ValueError(f"Unrecognized checkpoint format at {path}")


def _report_key_mismatch(
    model: nn.Module,
    state_dict: dict[str, torch.Tensor],
) -> None:
    model_keys = set(model.state_dict().keys())
    ckpt_keys = set(state_dict.keys())
    missing = sorted(model_keys - ckpt_keys)
    unexpected = sorted(ckpt_keys - model_keys)
    logger.error("State dict key mismatch.")
    logger.error("Missing keys (%d): %s", len(missing), missing[:40])
    logger.error("Unexpected keys (%d): %s", len(unexpected), unexpected[:40])
    if missing:
        logger.error("... remaining missing: %d", max(0, len(missing) - 40))
    if unexpected:
        logger.error("... remaining unexpected: %d", max(0, len(unexpected) - 40))


def load_runtime(model_dir: Path) -> dict[str, Any]:
    if not model_dir.is_dir():
        raise FileNotFoundError(f"MODEL_DIR does not exist: {model_dir}")

    state_dict_path = _find_state_dict_path(model_dir)
    logger.info("Loading state dict from %s", state_dict_path)

    feature_dir = model_dir / "feature_extractor"
    if feature_dir.is_dir():
        feature_extractor = Wav2Vec2FeatureExtractor.from_pretrained(str(feature_dir))
    else:
        logger.warning(
            "feature_extractor/ missing under %s; loading from %s",
            model_dir,
            BASE_MODEL_NAME,
        )
        feature_extractor = Wav2Vec2FeatureExtractor.from_pretrained(BASE_MODEL_NAME)

    state_dict = _load_state_dict(state_dict_path)
    score_fields = _align_score_fields(
        _load_score_fields(model_dir),
        _checkpoint_label_count(state_dict),
    )

    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    model = SerRegressionModel(num_labels=len(score_fields))

    try:
        model.load_state_dict(state_dict, strict=True)
    except RuntimeError as exc:
        _report_key_mismatch(model, state_dict)
        raise RuntimeError(
            f"Failed to load state_dict with strict=True: {exc}"
        ) from exc

    model.to(device)
    model.eval()

    version = _resolve_model_version()
    logger.info(
        "SER model loaded (version=%s, device=%s, fields=%s, checkpoint=%s)",
        version,
        device,
        score_fields,
        state_dict_path.name,
    )

    return {
        "model": model,
        "feature_extractor": feature_extractor,
        "score_fields": score_fields,
        "device": device,
        "model_version": version,
        "model_dir": str(model_dir),
        "sample_rate": int(getattr(feature_extractor, "sampling_rate", TARGET_SR) or TARGET_SR),
    }


def _export_wav_via_bundled_ffmpeg(src: str, wav_path: str) -> None:
    """Fallback: decode any container with the imageio-ffmpeg binary (no ffprobe)."""
    cmd = [
        _FFMPEG_EXE,
        "-hide_banner",
        "-loglevel",
        "error",
        "-y",
        "-i",
        src,
        "-vn",
        "-acodec",
        "pcm_s16le",
        wav_path,
    ]
    result = subprocess.run(cmd, capture_output=True, check=False)
    if result.returncode != 0 or not Path(wav_path).is_file():
        detail = result.stderr.decode(errors="ignore").strip() or "ffmpeg failed"
        raise RuntimeError(f"Bundled ffmpeg could not decode audio: {detail}")


def _load_waveform_without_torchcodec(path: str) -> tuple[torch.Tensor, int]:
    """
    Decode mp3/wav/m4a/ogg/webm (etc.) via pydub + bundled imageio-ffmpeg,
    then read the normalized WAV with soundfile — no torchcodec / system FFmpeg.
    """
    suffix = Path(path).suffix.lower()
    wav_path: str | None = None
    try:
        # Plain WAV: soundfile alone (no ffmpeg needed).
        if suffix == ".wav":
            data, sr = sf.read(path, always_2d=True, dtype="float32")
            waveform = torch.from_numpy(np.ascontiguousarray(data.T))
            return waveform, int(sr)

        with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as wav_tmp:
            wav_path = wav_tmp.name

        # Prefer pydub; pass format+codec so it does not invoke missing system ffprobe.
        fmt_codec = _AUDIO_FORMAT_CODEC.get(suffix)
        try:
            if fmt_codec is not None:
                fmt, codec = fmt_codec
                audio = AudioSegment.from_file(path, format=fmt, codec=codec)
            else:
                raise RuntimeError(f"No pydub format mapping for {suffix}")
            audio.export(wav_path, format="wav")
        except Exception as exc:  # noqa: BLE001
            logger.warning(
                "pydub decode failed (%s); falling back to bundled ffmpeg for %s",
                exc,
                path,
            )
            _export_wav_via_bundled_ffmpeg(path, wav_path)

        data, sr = sf.read(wav_path, always_2d=True, dtype="float32")
        waveform = torch.from_numpy(np.ascontiguousarray(data.T))
        return waveform, int(sr)
    finally:
        if wav_path is not None:
            try:
                os.unlink(wav_path)
            except OSError:
                pass


def _preprocess_audio(path: str, sample_rate: int) -> torch.Tensor:
    """Load → mono → resample → truncate/pad to exactly 8 seconds."""
    waveform, sr = _load_waveform_without_torchcodec(path)

    if waveform.dim() > 1 and waveform.size(0) > 1:
        waveform = waveform.mean(dim=0, keepdim=True)
    elif waveform.dim() == 1:
        waveform = waveform.unsqueeze(0)

    if int(sr) != sample_rate:
        waveform = torchaudio.functional.resample(waveform, int(sr), sample_rate)

    waveform = waveform.squeeze(0)
    n = waveform.numel()
    if n > TARGET_SAMPLES:
        waveform = waveform[:TARGET_SAMPLES]
    elif n < TARGET_SAMPLES:
        waveform = torch.nn.functional.pad(waveform, (0, TARGET_SAMPLES - n))

    return waveform.contiguous()


runtime: dict[str, Any] | None = None


@asynccontextmanager
async def lifespan(_app: FastAPI):
    global runtime
    model_dir = _resolve_model_dir()
    try:
        runtime = load_runtime(model_dir)
    except Exception as exc:  # noqa: BLE001
        logger.exception("Failed to load SER model from %s", model_dir)
        runtime = {
            "error": str(exc),
            "model_version": _resolve_model_version(),
            "model_dir": str(model_dir),
            "device": "cuda" if torch.cuda.is_available() else "cpu",
        }
    yield
    runtime = None


app = FastAPI(title="Acoustic SER Service", lifespan=lifespan)


@app.get("/health")
def health() -> dict[str, str]:
    if runtime is None or "model" not in runtime:
        detail = (runtime or {}).get("error", "model not loaded")
        raise HTTPException(status_code=503, detail=f"SER model unavailable: {detail}")

    return {
        "status": "ok",
        "model_version": str(runtime["model_version"]),
        "device": str(runtime["device"]),
    }


@app.post("/predict")
async def predict(file: UploadFile | None = File(None)) -> dict[str, Any]:
    if runtime is None or "model" not in runtime:
        detail = (runtime or {}).get("error", "model not loaded")
        raise HTTPException(status_code=503, detail=f"SER model unavailable: {detail}")

    if file is None:
        raise HTTPException(status_code=400, detail="Missing audio file (multipart field 'file')")

    data = await file.read()
    if not data:
        raise HTTPException(status_code=400, detail="Empty audio upload")

    suffix = Path(file.filename or "audio.wav").suffix or ".wav"
    tmp_path: str | None = None
    try:
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(data)
            tmp_path = tmp.name

        sample_rate = int(runtime.get("sample_rate", TARGET_SR))
        waveform = _preprocess_audio(tmp_path, sample_rate)

        feature_extractor: Wav2Vec2FeatureExtractor = runtime["feature_extractor"]
        inputs = feature_extractor(
            waveform.numpy(),
            sampling_rate=sample_rate,
            return_tensors="pt",
            padding=False,
        )

        device: torch.device = runtime["device"]
        input_values = inputs.input_values.to(device)
        attention_mask = inputs.get("attention_mask")
        if attention_mask is not None:
            attention_mask = attention_mask.to(device)

        model: SerRegressionModel = runtime["model"]
        with torch.inference_mode():
            probs = model(input_values=input_values, attention_mask=attention_mask)
            scores = (probs.squeeze(0).detach().cpu().numpy() * 100.0).tolist()

        fields: list[str] = runtime["score_fields"]
        payload: dict[str, Any] = {
            field: float(scores[index]) if index < len(scores) else 0.0
            for index, field in enumerate(fields)
        }
        # Checkpoint still has 4 heads including aggression; shrinking num_labels
        # would fail load. Drop the field from the HTTP contract only.
        payload.pop("aggression_score", None)
        payload["model_version"] = str(runtime["model_version"])
        return payload
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        logger.exception("Inference failed")
        raise HTTPException(status_code=500, detail=f"Inference failed: {exc}") from exc
    finally:
        if tmp_path is not None:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
