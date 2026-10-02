# Acoustic SER inference service

Lokalla işləyən FastAPI servisi: Colab-da öyrədilmiş wav2vec2 + LoRA Speech Emotion Recognition (SER) modelini yükləyib audio skorlayır.

## Quraşdırma

```bash
cd python/ser_service
python -m venv venv

# Windows
venv\Scripts\activate

# macOS / Linux
source venv/bin/activate

pip install -r requirements.txt
```

Audio decode üçün `pydub` + `imageio-ffmpeg` istifadə olunur (`audioop-lts` Python 3.13+ üçün lazımdır). `imageio-ffmpeg` öz ffmpeg binary-sini pip ilə gətirir — **ayrıca sistem FFmpeg quraşdırmaq lazım deyil**, `torchcodec` də tələb olunmur. Beləliklə mp3 / wav / m4a / ogg / webm (Laravel upload formatları) işləyir.

## Model qovluğu

`MODEL_DIR` dəyişənini Colab-dan endirilən `final_model/` qovluğuna yönəldin. Gözlənilən məzmun:

```
final_model/
├── ser_model_state_dict.pt   # və ya best_model.pt (state dict)
├── feature_extractor/        # HuggingFace Wav2Vec2FeatureExtractor
└── score_fields.json         # 4 skor adının JSON massivi
```

Ətraf mühit dəyişənləri:

| Dəyişən | Məqsəd | Default |
|---------|--------|---------|
| `MODEL_DIR` | `final_model/` yolu | `./ser_model/final_model` |
| `MODEL_VERSION` | Cavablarda qaytarılan versiya | `unknown` |

## Servisi işə salmaq

```bash
# python/ser_service/ qovluğundan
set MODEL_DIR=..\ser_model\final_model
set MODEL_VERSION=v1

uvicorn app:app --host 0.0.0.0 --port 8001
```

Health yoxlaması:

```bash
curl http://localhost:8001/health
```

Gözlənilən cavab: `{"status":"ok","model_version":"v1","device":"cpu"}`

Predict (multipart audio):

```bash
curl -X POST http://localhost:8001/predict -F "file=@sample.wav"
```

Skorlar **0–100** float-dur (sigmoid × 100).

## Yeni model versiyasını dəyişmək

1. Colab-dan yeni `final_model/` endirin (və ya mövcud qovluğu əvəz edin).
2. `MODEL_DIR`-i yeni yola qoyun (lazımdırsa).
3. `MODEL_VERSION`-u yeniləyin (məs. `v2`).
4. **Uvicorn prosesini restart edin** — model yalnız startup-da yüklənir.
5. Laravel tərəfdən: `php artisan calls:rescore-with-ser --all` (`queue:work` işləməlidir).
