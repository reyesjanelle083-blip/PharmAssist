import { useCallback, useEffect, useId, useState } from 'react'

const SLIDES = [
  { icon: 'bi bi-search', category: 'Step 1', title: 'Search a medicine', text: 'Type the name of a medicine, generic or brand, in the search bar at the top.' },
  { icon: 'bi bi-geo-alt-fill', category: 'Step 2', title: 'See which branch has it and how far', text: 'Share your location for one search and the branches with stock are ranked by travel time.' },
  { icon: 'bi bi-bag-check-fill', category: 'Step 3', title: 'Reserve and get a claim code', text: 'Reserve what you need. After the pharmacy approves it you get a claim code.' },
  { icon: 'bi bi-qr-code', category: 'Step 4', title: 'Show the code at the branch', text: 'Show your claim code at the branch, collect your medicine and pay there.' },
]

const INTERVAL_MS = 6000

function prefersReducedMotion() {
  try {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
  } catch {
    return false
  }
}

/**
 * "How it works": a carousel of our own slides (no external feeds or pictures). Accessible by the carousel pattern:
 * a labelled region, slides announced as "1 of 4", previous and next buttons, slide dots, and a pause / play button.
 * It does not move by itself when the person prefers reduced motion, and it stops while the mouse or keyboard focus is on it.
 */
export default function Carousel() {
  const labelId = useId()
  const [index, setIndex] = useState(0)
  const [playing, setPlaying] = useState(() => !prefersReducedMotion())
  const [hovering, setHovering] = useState(false)
  const [focusWithin, setFocusWithin] = useState(false)

  const go = useCallback((next) => setIndex((next + SLIDES.length) % SLIDES.length), [])

  useEffect(() => {
    if (!playing || hovering || focusWithin) return undefined
    const timer = setInterval(() => setIndex((current) => (current + 1) % SLIDES.length), INTERVAL_MS)
    return () => clearInterval(timer)
  }, [playing, hovering, focusWithin])

  return (
    <section
      className="carousel-container legacy-carousel"
      aria-roledescription="carousel"
      aria-labelledby={labelId}
      onMouseEnter={() => setHovering(true)}
      onMouseLeave={() => setHovering(false)}
      onFocus={() => setFocusWithin(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setFocusWithin(false)
      }}
    >
      <h2 id={labelId} className="visually-hidden">
        How PharmAssist works
      </h2>

      <div className="carousel-wrapper" style={{ transform: `translateX(-${index * 100}%)` }} aria-live={playing && !hovering && !focusWithin ? 'off' : 'polite'}>
        {SLIDES.map((slide, i) => (
          <div
            key={slide.title}
            className="carousel-slide"
            role="group"
            aria-roledescription="slide"
            aria-label={`${i + 1} of ${SLIDES.length}`}
            inert={i !== index}
          >
            <div className="card carousel-card">
              <div className="carousel-art" aria-hidden="true">
                <i className={slide.icon}></i>
              </div>
              <div className="card-overlay">
                <div className="card-category">{slide.category}</div>
                <h3 className="card-title">{slide.title}</h3>
                <div className="card-description">{slide.text}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      <button type="button" className="carousel-nav prev" onClick={() => go(index - 1)} aria-label="Previous slide">
        <i className="bi bi-chevron-left" aria-hidden="true"></i>
      </button>
      <button type="button" className="carousel-nav next" onClick={() => go(index + 1)} aria-label="Next slide">
        <i className="bi bi-chevron-right" aria-hidden="true"></i>
      </button>

      <div className="carousel-indicators">
        <button type="button" className="carousel-toggle" onClick={() => setPlaying((value) => !value)} aria-label={playing ? 'Pause the slideshow' : 'Start the slideshow'}>
          <i className={playing ? 'bi bi-pause-fill' : 'bi bi-play-fill'} aria-hidden="true"></i>
        </button>
        {SLIDES.map((slide, i) => (
          <button
            key={slide.title}
            type="button"
            className={i === index ? 'indicator active' : 'indicator'}
            onClick={() => go(i)}
            aria-label={`Go to slide ${i + 1}: ${slide.title}`}
            aria-current={i === index ? 'true' : undefined}
          ></button>
        ))}
      </div>
    </section>
  )
}
