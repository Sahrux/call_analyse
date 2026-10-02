"""Sanity checks for the SER head, labels, and learning rate.

The wav2vec2 backbone is replaced with a tiny fixed projection so this can run
without downloading weights. Every check still goes through SerRegressionModel.forward.

(a) Overfit 16 samples to ~100% train accuracy within 50 steps.
(b) Logits differ across different inputs.
(c) Classifier weights change after those steps.
"""

from __future__ import annotations

import sys
from pathlib import Path
from types import SimpleNamespace

import torch
import torch.nn as nn

sys.path.insert(0, str(Path(__file__).resolve().parent))

import app as ser_app  # noqa: E402

SAMPLES = 16
STEPS = 50
NUM_LABELS = 4
WAVEFORM_LENGTH = 64
HIDDEN_SIZE = 32
LEARNING_RATE = 1e-1
ACCURACY_TARGET = 0.99
LOGIT_DIFF_EPS = 1e-4
WEIGHT_DELTA_EPS = 1e-6


class _EncoderOutput:
    def __init__(self, last_hidden_state: torch.Tensor) -> None:
        self.last_hidden_state = last_hidden_state


class _FeatureExtractor(nn.Module):
    def _freeze_parameters(self) -> None:
        return None


class _FakeWav2Vec2(nn.Module):
    """Fixed projection so distinct waveforms stay distinct after mean-pool."""

    def __init__(self, hidden_size: int = HIDDEN_SIZE) -> None:
        super().__init__()
        self.config = SimpleNamespace(hidden_size=hidden_size)
        self.feature_extractor = _FeatureExtractor()
        projection = torch.randn(WAVEFORM_LENGTH, hidden_size)
        self.register_buffer("projection", projection)

    def forward(
        self,
        input_values: torch.Tensor,
        attention_mask: torch.Tensor | None = None,
    ) -> _EncoderOutput:
        hidden = input_values @ self.projection
        return _EncoderOutput(hidden.unsqueeze(1))


def _install_fake_backbone() -> None:
    ser_app.Wav2Vec2Model.from_pretrained = lambda *_args, **_kwargs: _FakeWav2Vec2()
    ser_app.get_peft_model = lambda base, _config: base


def _last_linear(model: nn.Module) -> nn.Linear:
    linear_layers = [module for module in model.regressor if isinstance(module, nn.Linear)]
    if not linear_layers:
        raise RuntimeError("SER head has no Linear classifier.")
    return linear_layers[-1]


def _disable_dropout(model: nn.Module) -> None:
    for module in model.modules():
        if isinstance(module, nn.Dropout):
            module.p = 0.0


def _forward_logits(model: ser_app.SerRegressionModel, input_values: torch.Tensor) -> torch.Tensor:
    captured: dict[str, torch.Tensor] = {}

    def _hook(_module: nn.Module, _inputs: tuple[torch.Tensor, ...], output: torch.Tensor) -> None:
        captured["logits"] = output

    handle = _last_linear(model).register_forward_hook(_hook)
    try:
        probabilities = model(input_values=input_values)
    finally:
        handle.remove()

    if "logits" not in captured:
        raise RuntimeError("Classifier forward hook did not capture logits.")

    expected = torch.sigmoid(captured["logits"])
    if not torch.allclose(probabilities, expected, atol=1e-5):
        raise RuntimeError("Model forward does not match sigmoid(classifier logits).")

    return captured["logits"]


def _train_accuracy(logits: torch.Tensor, labels: torch.Tensor) -> float:
    predictions = (logits.detach().sigmoid() >= 0.5).to(labels.dtype)
    return float((predictions == labels).all(dim=1).float().mean().item())


def _check_logits_differ(logits: torch.Tensor) -> float:
    pairwise = (logits.unsqueeze(0) - logits.unsqueeze(1)).abs().sum(dim=-1)
    off_diagonal = pairwise + torch.eye(logits.size(0), device=logits.device) * 1e9
    return float(off_diagonal.min().item())


def main() -> int:
    torch.manual_seed(0)
    _install_fake_backbone()

    model = ser_app.SerRegressionModel(num_labels=NUM_LABELS)
    _disable_dropout(model)
    model.train()

    generator = torch.Generator().manual_seed(0)
    input_values = torch.randn(SAMPLES, WAVEFORM_LENGTH, generator=generator)
    labels = torch.randint(0, 2, (SAMPLES, NUM_LABELS), generator=generator, dtype=torch.float32)

    initial_logits = _forward_logits(model, input_values).detach()
    min_logit_gap = _check_logits_differ(initial_logits)
    logits_ok = min_logit_gap > LOGIT_DIFF_EPS

    classifier = _last_linear(model)
    weights_before = classifier.weight.detach().clone()

    optimizer = torch.optim.AdamW(model.regressor.parameters(), lr=LEARNING_RATE, weight_decay=0.0)
    loss_fn = nn.BCEWithLogitsLoss()

    accuracy = 0.0
    loss_value = float("inf")
    reached_step = STEPS

    for step in range(1, STEPS + 1):
        optimizer.zero_grad(set_to_none=True)
        logits = _forward_logits(model, input_values)
        loss = loss_fn(logits, labels)
        loss.backward()
        optimizer.step()

        loss_value = float(loss.item())
        accuracy = _train_accuracy(logits, labels)
        print(f"step {step:02d}  loss={loss_value:.4f}  train_acc={accuracy:.3f}")

        if accuracy >= ACCURACY_TARGET:
            reached_step = step
            break

    weight_delta = float((classifier.weight.detach() - weights_before).abs().max().item())
    accuracy_ok = accuracy >= ACCURACY_TARGET
    weights_ok = weight_delta > WEIGHT_DELTA_EPS

    print()
    print(
        f"(a) overfit {SAMPLES} samples within {STEPS} steps: "
        f"{'PASS' if accuracy_ok else 'FAIL'} "
        f"(train_acc={accuracy:.3f} at step {reached_step})"
    )
    print(
        f"(b) logits differ across inputs: "
        f"{'PASS' if logits_ok else 'FAIL'} "
        f"(min pairwise L1={min_logit_gap:.6f})"
    )
    print(
        f"(c) classifier weights changed: "
        f"{'PASS' if weights_ok else 'FAIL'} "
        f"(max |delta|={weight_delta:.6e})"
    )

    if accuracy_ok and logits_ok and weights_ok:
        print("sanity check: PASS")
        return 0

    if not accuracy_ok:
        print("sanity check: FAIL — likely a bug in the head, labels, or learning rate.")
    else:
        print("sanity check: FAIL")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
