import { useEffect, useRef, useState } from 'react'
import '../styles/medicine-scanner.css'

const MODEL_URL = '/models/medicine-scanner/model.json'
const METADATA_URL = '/models/medicine-scanner/metadata.json'
const MIN_CONFIDENCE = 0.7
const MAX_FILE_SIZE = 10 * 1024 * 1024

let modelPromise = null

async function loadScannerModel() {
  if (!window.tmImage?.load) {
    throw new Error(
      'The medicine scanner library could not be loaded. Please refresh the page and try again.',
    )
  }

  if (!modelPromise) {
    modelPromise = window.tmImage.load(MODEL_URL, METADATA_URL).catch((error) => {
      modelPromise = null
      throw error
    })
  }

  return modelPromise
}

function imageFromSource(src) {
  return new Promise((resolve, reject) => {
    const image = new Image()

    image.onload = () => resolve(image)
    image.onerror = () => reject(new Error('The selected image could not be read.'))
    image.src = src
  })
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()

    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(new Error('The selected image could not be read.'))
    reader.readAsDataURL(file)
  })
}

function centerCrop(source, width, height) {
  const canvas = document.createElement('canvas')
  canvas.width = 224
  canvas.height = 224

  const context = canvas.getContext('2d')

  const cropSize = Math.min(width, height)
  const sourceX = (width - cropSize) / 2
  const sourceY = (height - cropSize) / 2

  context.drawImage(
    source,
    sourceX,
    sourceY,
    cropSize,
    cropSize,
    0,
    0,
    224,
    224,
  )

  return canvas
}

export default function MedicineScanner({
  open,
  onClose,
  onUseDetection,
}) {
  const videoRef = useRef(null)
  const streamRef = useRef(null)

  const [mode, setMode] = useState('upload')
  const [phase, setPhase] = useState('idle')
  const [preview, setPreview] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  function stopCamera() {
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((track) => track.stop())
      streamRef.current = null
    }

    if (videoRef.current) {
      videoRef.current.srcObject = null
    }
  }

  function resetScan() {
    setPreview('')
    setResult(null)
    setError('')
    setPhase('idle')
  }

  function closeScanner() {
    stopCamera()
    resetScan()
    setMode('upload')
    onClose()
  }

  useEffect(() => {
    if (!open) {
      stopCamera()
      return undefined
    }

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        closeScanner()
      }
    }

    document.addEventListener('keydown', onKeyDown)

    loadScannerModel().catch(() => {
      // The scan itself will show the full error if model loading fails.
    })

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      stopCamera()
    }
  }, [open])

  async function predict(canvas) {
    setError('')
    setResult(null)
    setPhase('scanning')

    try {
      const model = await loadScannerModel()
      const predictions = await model.predict(canvas)

      const sorted = [...predictions]
        .map((prediction) => ({
          label: prediction.className,
          probability: Number(prediction.probability),
        }))
        .sort((a, b) => b.probability - a.probability)

      const best = sorted[0]

      if (!best) {
        throw new Error('The scanner did not return a prediction.')
      }

      setResult({
        label: best.label,
        confidence: best.probability,
        predictions: sorted,
        accepted: best.probability >= MIN_CONFIDENCE,
      })

      setPhase('done')
    } catch (scanError) {
      setError(
        scanError?.message ||
          'The medicine could not be scanned. Please try another image.',
      )
      setPhase('error')
    }
  }

  async function scanUploadedFile(file) {
    if (!file) return

    if (!file.type.startsWith('image/')) {
      setError('Please select an image file.')
      return
    }

    if (file.size > MAX_FILE_SIZE) {
      setError('The image is too large. Maximum file size is 10 MB.')
      return
    }

    try {
      setError('')
      setPhase('preparing')

      const source = await fileToDataUrl(file)
      setPreview(source)

      const image = await imageFromSource(source)

      const canvas = centerCrop(
        image,
        image.naturalWidth || image.width,
        image.naturalHeight || image.height,
      )

      await predict(canvas)
    } catch (uploadError) {
      setError(
        uploadError?.message ||
          'The selected image could not be processed.',
      )
      setPhase('error')
    }
  }

  async function startCamera() {
    stopCamera()
    resetScan()
    setMode('camera')

    try {
      setPhase('camera-loading')

      if (!navigator.mediaDevices?.getUserMedia) {
        throw new Error('Camera access is not supported by this browser.')
      }

      const stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: {
            ideal: 'environment',
          },
        },
        audio: false,
      })

      streamRef.current = stream

      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }

      setPhase('camera-ready')
    } catch (cameraError) {
      stopCamera()
      setError(
        cameraError?.message ||
          'Camera access was denied or is unavailable.',
      )
      setPhase('error')
    }
  }

  function switchToUpload() {
    stopCamera()
    resetScan()
    setMode('upload')
  }

  async function captureCamera() {
    const video = videoRef.current

    if (!video?.videoWidth || !video?.videoHeight) {
      setError('The camera is not ready yet. Please try again.')
      return
    }

    const capture = document.createElement('canvas')
    capture.width = video.videoWidth
    capture.height = video.videoHeight

    const context = capture.getContext('2d')
    context.drawImage(video, 0, 0, capture.width, capture.height)

    setPreview(capture.toDataURL('image/jpeg', 0.9))

    const modelInput = centerCrop(
      capture,
      capture.width,
      capture.height,
    )

    await predict(modelInput)
  }

  function useDetection() {
    if (!result?.accepted) return

    onUseDetection(result.label)
    closeScanner()
  }

  if (!open) return null

  const confidence = result
    ? Math.round(result.confidence * 1000) / 10
    : 0

  return (
    <div
      className="medicine-scanner-overlay"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          closeScanner()
        }
      }}
    >
      <section
        className="medicine-scanner-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="medicine-scanner-title"
      >
        <header className="medicine-scanner-header">
          <div>
            <p className="medicine-scanner-kicker">Image recognition</p>
            <h2 id="medicine-scanner-title">Scan a medicine</h2>
            <p>
              Upload a medicine package photo or use your camera to identify
              the product.
            </p>
          </div>

          <button
            type="button"
            className="medicine-scanner-close"
            aria-label="Close medicine scanner"
            onClick={closeScanner}
          >
            <i className="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </header>

        <div className="medicine-scanner-support-note">
          <i className="bi bi-info-circle" aria-hidden="true"></i>
          <span>
            Current trained model recognizes <strong>Ibuprofen</strong> and{' '}
            <strong>Biogesic</strong>.
          </span>
        </div>

        <div className="medicine-scanner-tabs">
          <button
            type="button"
            className={mode === 'upload' ? 'active' : ''}
            onClick={switchToUpload}
          >
            <i className="bi bi-image" aria-hidden="true"></i>
            Upload image
          </button>

          <button
            type="button"
            className={mode === 'camera' ? 'active' : ''}
            onClick={startCamera}
          >
            <i className="bi bi-camera" aria-hidden="true"></i>
            Use camera
          </button>
        </div>

        <div className="medicine-scanner-body">
          <div className="medicine-scanner-source">
            {mode === 'upload' ? (
              <>
                <label className="medicine-scanner-upload">
                  <input
                    type="file"
                    accept="image/*"
                    onChange={(event) =>
                      scanUploadedFile(event.target.files?.[0])
                    }
                  />

                  {preview ? (
                    <img src={preview} alt="Medicine selected for scanning" />
                  ) : (
                    <div>
                      <i className="bi bi-cloud-arrow-up" aria-hidden="true"></i>
                      <strong>Choose a medicine photo</strong>
                      <span>JPG, PNG, WEBP — up to 10 MB</span>
                    </div>
                  )}
                </label>

                {preview ? (
                  <label className="medicine-scanner-replace">
                    <input
                      type="file"
                      accept="image/*"
                      onChange={(event) =>
                        scanUploadedFile(event.target.files?.[0])
                      }
                    />
                    <i className="bi bi-arrow-repeat" aria-hidden="true"></i>
                    Choose another image
                  </label>
                ) : null}
              </>
            ) : (
              <div className="medicine-scanner-camera">
                <video
                  ref={videoRef}
                  autoPlay
                  muted
                  playsInline
                />

                {phase === 'camera-ready' ? (
                  <button
                    type="button"
                    className="medicine-scanner-capture"
                    onClick={captureCamera}
                  >
                    <i className="bi bi-camera-fill" aria-hidden="true"></i>
                    Capture and scan
                  </button>
                ) : null}

                {preview ? (
                  <img
                    className="medicine-scanner-camera-preview"
                    src={preview}
                    alt="Captured medicine"
                  />
                ) : null}
              </div>
            )}
          </div>

          <div className="medicine-scanner-result">
            {phase === 'idle' || phase === 'camera-ready' ? (
              <div className="medicine-scanner-empty">
                <i className="bi bi-upc-scan" aria-hidden="true"></i>
                <h3>Ready to scan</h3>
                <p>
                  Keep the medicine packaging clear, centered, and well lit.
                </p>
              </div>
            ) : null}

            {phase === 'preparing' ||
            phase === 'scanning' ||
            phase === 'camera-loading' ? (
              <div className="medicine-scanner-loading" aria-live="polite">
                <span className="medicine-scanner-spinner" aria-hidden="true"></span>
                <strong>
                  {phase === 'camera-loading'
                    ? 'Starting camera...'
                    : phase === 'preparing'
                      ? 'Preparing image...'
                      : 'Analyzing medicine...'}
                </strong>
                <p>The recognition model is processing the image.</p>
              </div>
            ) : null}

            {error ? (
              <div className="medicine-scanner-error" role="alert">
                <i className="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                <div>
                  <strong>Scanner unavailable</strong>
                  <p>{error}</p>
                </div>
              </div>
            ) : null}

            {result ? (
              <div className="medicine-scanner-detection">
                <div className="medicine-scanner-result-icon">
                  <i className="bi bi-capsule-pill" aria-hidden="true"></i>
                </div>

                <p className="medicine-scanner-result-label">
                  Detected medicine
                </p>

                <h3>{result.label}</h3>

                <div className="medicine-scanner-confidence">
                  <div>
                    <span>Model confidence</span>
                    <strong>{confidence}%</strong>
                  </div>

                  <div className="medicine-scanner-confidence-track">
                    <span
                      style={{
                        width: `${Math.min(confidence, 100)}%`,
                      }}
                    />
                  </div>
                </div>

                {!result.accepted ? (
                  <p className="medicine-scanner-low-confidence">
                    Confidence is too low to search automatically. Try a clearer
                    photo of the front of the medicine package.
                  </p>
                ) : (
                  <p className="medicine-scanner-match-note">
                    The detected name will be searched against the live
                    PharmAssist medicine catalog.
                  </p>
                )}

                <div className="medicine-scanner-predictions">
                  {result.predictions.map((prediction) => (
                    <div key={prediction.label}>
                      <span>{prediction.label}</span>
                      <strong>
                        {(prediction.probability * 100).toFixed(1)}%
                      </strong>
                    </div>
                  ))}
                </div>

                <button
                  type="button"
                  className="medicine-scanner-search"
                  disabled={!result.accepted}
                  onClick={useDetection}
                >
                  <i className="bi bi-search" aria-hidden="true"></i>
                  Search {result.label} in PharmAssist
                </button>
              </div>
            ) : null}
          </div>
        </div>
      </section>
    </div>
  )
}