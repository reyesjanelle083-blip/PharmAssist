import { useCallback, useEffect, useRef, useState } from 'react'
import { ocrMedicineLabel } from '../api/index.js'
import '../styles/medicine-label-scanner.css'

const MAX_FILE_SIZE = 5 * 1024 * 1024

function confidencePercent(value) {
  if (value === null || value === undefined) return null

  return Math.round(Number(value) * 1000) / 10
}

function fieldValue(value) {
  return value || 'Not detected'
}

export default function MedicineLabelScanner({
  open,
  onClose,
  onUseMedicine,
}) {
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const requestRef = useRef(null)

  const [mode, setMode] = useState('upload')
  const [phase, setPhase] = useState('idle')
  const [preview, setPreview] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  const stopCamera = useCallback(() => {
    if (streamRef.current) {
        streamRef.current.getTracks().forEach((track) => track.stop())
        streamRef.current = null
    }

    if (videoRef.current) {
        videoRef.current.srcObject = null
    }
    }, [])

    const cancelRequest = useCallback(() => {
    requestRef.current?.abort()
    requestRef.current = null
    }, [])

    const reset = useCallback(() => {
    cancelRequest()
    setPreview('')
    setResult(null)
    setError('')
    setPhase('idle')
    }, [cancelRequest])

    const closeScanner = useCallback(() => {
    stopCamera()
    reset()
    setMode('upload')
    onClose()
    }, [onClose, reset, stopCamera])

  useEffect(() => {
    if (!open) {
      stopCamera()
      cancelRequest()
      return undefined
    }

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        closeScanner()
      }
    }

    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      stopCamera()
      cancelRequest()
    }
  }, [open, cancelRequest, closeScanner, stopCamera])

  async function scan(image) {
    cancelRequest()

    const controller = new AbortController()
    requestRef.current = controller

    setError('')
    setResult(null)
    setPhase('scanning')

    try {
      const response = await ocrMedicineLabel(image, {
        signal: controller.signal,
      })

      const data = response.data ?? {}

      setResult({
        medicineName: data.medicine_name ?? null,
        dosage: data.dosage ?? null,
        batchNo: data.batch_no ?? null,
        expirationDate: data.expiration_date ?? null,
        ocrText: data.ocr_text ?? null,
        confidence: data.confidence ?? null,
        status: data.ocr_status ?? 'unavailable',
        source: data.source ?? null,
        warnings: response.warnings ?? [],
      })

      setPhase('done')
    } catch (scanError) {
      if (
        scanError?.name === 'CanceledError' ||
        scanError?.name === 'AbortError'
      ) {
        return
      }

      setError(
        scanError?.message ||
          'The medicine label could not be scanned. Please try again.',
      )

      setPhase('error')
    } finally {
      requestRef.current = null
    }
  }

  async function chooseFile(file) {
    if (!file) return

    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setError('Please select a JPEG or PNG image.')
      return
    }

    if (file.size > MAX_FILE_SIZE) {
      setError('The image must not be larger than 5 MB.')
      return
    }

    reset()
    setMode('upload')

    const url = URL.createObjectURL(file)
    setPreview(url)

    await scan(file)
  }

  async function startCamera() {
    stopCamera()
    reset()
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
    reset()
    setMode('upload')
  }

  async function captureAndScan() {
    const video = videoRef.current

    if (!video?.videoWidth || !video?.videoHeight) {
      setError('The camera is not ready yet.')
      return
    }

    const canvas = document.createElement('canvas')

    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const context = canvas.getContext('2d')

    context.drawImage(
      video,
      0,
      0,
      canvas.width,
      canvas.height,
    )

    setPreview(canvas.toDataURL('image/jpeg', 0.92))

    canvas.toBlob(
      async (blob) => {
        if (!blob) {
          setError('The captured image could not be prepared.')
          return
        }

        await scan(blob)
      },
      'image/jpeg',
      0.92,
    )
  }

  function searchDetectedMedicine() {
    if (!result?.medicineName) return

    onUseMedicine(result.medicineName)
    closeScanner()
  }

  if (!open) return null

  const confidence = confidencePercent(result?.confidence)

  return (
    <div
      className="label-scanner-overlay"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          closeScanner()
        }
      }}
    >
      <section
        className="label-scanner-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="label-scanner-title"
      >
        <header className="label-scanner-header">
          <div>
            <p className="label-scanner-kicker">
              Medicine label OCR
            </p>

            <h2 id="label-scanner-title">
              Scan medicine label
            </h2>

            <p>
              Scan the printed package label to extract medicine
              information.
            </p>
          </div>

          <button
            type="button"
            className="label-scanner-close"
            aria-label="Close label scanner"
            onClick={closeScanner}
          >
            <i
              className="bi bi-x-lg"
              aria-hidden="true"
            ></i>
          </button>
        </header>

        <div className="label-scanner-note">
          <i
            className="bi bi-info-circle"
            aria-hidden="true"
          ></i>

          <span>
            OCR results may contain errors. Always verify the
            information against the medicine package.
          </span>
        </div>

        <div className="label-scanner-tabs">
          <button
            type="button"
            className={mode === 'upload' ? 'active' : ''}
            onClick={switchToUpload}
          >
            <i
              className="bi bi-image"
              aria-hidden="true"
            ></i>
            Upload image
          </button>

          <button
            type="button"
            className={mode === 'camera' ? 'active' : ''}
            onClick={startCamera}
          >
            <i
              className="bi bi-camera"
              aria-hidden="true"
            ></i>
            Use camera
          </button>
        </div>

        <div className="label-scanner-body">
          <div className="label-scanner-image-panel">
            {mode === 'upload' ? (
              <>
                <label className="label-scanner-upload">
                  <input
                    type="file"
                    accept="image/jpeg,image/png"
                    onChange={(event) =>
                      chooseFile(event.target.files?.[0])
                    }
                  />

                  {preview ? (
                    <img
                      src={preview}
                      alt="Medicine label selected for OCR"
                    />
                  ) : (
                    <div>
                      <i
                        className="bi bi-card-image"
                        aria-hidden="true"
                      ></i>

                      <strong>
                        Choose a medicine label photo
                      </strong>

                      <span>
                        JPEG or PNG · Maximum 5 MB
                      </span>
                    </div>
                  )}
                </label>

                {preview ? (
                  <label className="label-scanner-replace">
                    <input
                      type="file"
                      accept="image/jpeg,image/png"
                      onChange={(event) =>
                        chooseFile(event.target.files?.[0])
                      }
                    />

                    <i
                      className="bi bi-arrow-repeat"
                      aria-hidden="true"
                    ></i>

                    Choose another image
                  </label>
                ) : null}
              </>
            ) : (
              <div className="label-scanner-camera">
                <video
                  ref={videoRef}
                  autoPlay
                  muted
                  playsInline
                />

                {phase === 'camera-ready' ? (
                  <button
                    type="button"
                    className="label-scanner-capture"
                    onClick={captureAndScan}
                  >
                    <i
                      className="bi bi-camera-fill"
                      aria-hidden="true"
                    ></i>
                    Capture and scan
                  </button>
                ) : null}

                {preview ? (
                  <img
                    className="label-scanner-camera-preview"
                    src={preview}
                    alt="Captured medicine label"
                  />
                ) : null}
              </div>
            )}
          </div>

          <div className="label-scanner-result-panel">
            {phase === 'idle' ||
            phase === 'camera-ready' ? (
              <div className="label-scanner-empty">
                <i
                  className="bi bi-textarea-t"
                  aria-hidden="true"
                ></i>

                <h3>Ready to read label</h3>

                <p>
                  Make sure the medicine name, strength,
                  batch number, and expiration date are
                  visible and in focus.
                </p>
              </div>
            ) : null}

            {phase === 'camera-loading' ||
            phase === 'scanning' ? (
              <div
                className="label-scanner-loading"
                aria-live="polite"
              >
                <span
                  className="label-scanner-spinner"
                  aria-hidden="true"
                ></span>

                <strong>
                  {phase === 'camera-loading'
                    ? 'Starting camera...'
                    : 'Reading medicine label...'}
                </strong>

                <p>
                  Tesseract OCR is processing the image.
                </p>
              </div>
            ) : null}

            {error ? (
              <div
                className="label-scanner-error"
                role="alert"
              >
                <i
                  className="bi bi-exclamation-circle-fill"
                  aria-hidden="true"
                ></i>

                <div>
                  <strong>Could not scan label</strong>
                  <p>{error}</p>
                </div>
              </div>
            ) : null}

            {result ? (
              <div className="label-scanner-results">
                <div className="label-scanner-result-heading">
                  <div>
                    <p>OCR result</p>
                    <h3>
                      {result.medicineName ||
                        'Medicine not detected'}
                    </h3>
                  </div>

                  <span
                    className={`label-scanner-status label-scanner-status--${result.status}`}
                  >
                    {result.status === 'success'
                      ? 'Read successfully'
                      : result.status === 'low_confidence'
                        ? 'Needs review'
                        : 'OCR unavailable'}
                  </span>
                </div>

                <dl className="label-scanner-fields">
                  <div>
                    <dt>Medicine name</dt>
                    <dd>
                      {fieldValue(result.medicineName)}
                    </dd>
                  </div>

                  <div>
                    <dt>Dosage / strength</dt>
                    <dd>
                      {fieldValue(result.dosage)}
                    </dd>
                  </div>

                  <div>
                    <dt>Batch number</dt>
                    <dd>
                      {fieldValue(result.batchNo)}
                    </dd>
                  </div>

                  <div>
                    <dt>Expiration date</dt>
                    <dd>
                      {fieldValue(
                        result.expirationDate,
                      )}
                    </dd>
                  </div>
                </dl>

                <div className="label-scanner-confidence">
                  <span>OCR confidence</span>

                  <strong>
                    {confidence === null
                      ? 'Not available'
                      : `${confidence}%`}
                  </strong>
                </div>

                {confidence !== null ? (
                  <div className="label-scanner-confidence-track">
                    <span
                      style={{
                        width: `${Math.min(
                          Math.max(confidence, 0),
                          100,
                        )}%`,
                      }}
                    />
                  </div>
                ) : null}

                {result.warnings.length > 0 ? (
                  <div className="label-scanner-warnings">
                    <strong>
                      Some information needs review
                    </strong>

                    <ul>
                      {result.warnings.map((warning) => (
                        <li key={warning}>
                          {warning
                            .toLowerCase()
                            .replaceAll('_', ' ')}
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : null}

                {result.ocrText ? (
                  <details className="label-scanner-raw">
                    <summary>
                      View raw extracted text
                    </summary>

                    <pre>{result.ocrText}</pre>
                  </details>
                ) : null}

                {result.medicineName ? (
                  <button
                    type="button"
                    className="label-scanner-search"
                    onClick={searchDetectedMedicine}
                  >
                    <i
                      className="bi bi-search"
                      aria-hidden="true"
                    ></i>

                    Search this medicine
                  </button>
                ) : null}
              </div>
            ) : null}
          </div>
        </div>
      </section>
    </div>
  )
}