import {
  useCallback,
  useEffect,
  useId,
  useState,
} from 'react'

import '../styles/carousel-external.css'

const SLIDES = [
  [
    {
      category: 'OBESITY • MEDICAL RESEARCH',
      title:
        'Obesity Treatment: Study Backs Dual-Drug Strategy',
      description:
        'Study on dual-drug strategy for obesity treatment effectiveness.',
      url:
        'https://www.medscape.com/viewarticle/obesity-treatment-study-backs-dual-drug-strategy-2025a1000pu3?form=fpf',
      image:
        'https://images.ctfassets.net/yixw23k2v6vo/6UcVlTNvtUSvHXU1x6HPQZ/7795e0da749cf7b4e2cc2a140c33e159/iStock-513070138.jpg',
      alt: 'Obesity treatment research',
    },
    {
      category:
        'PUBLIC HEALTH • ANTIMICROBIAL RESISTANCE • GLOBAL HEALTH',
      title:
        'The Lancet Series on Antimicrobial Resistance: The need for sustainable access to effective antibiotics',
      description:
        'Research on sustainable antibiotic access amid resistance challenges.',
      url:
        'https://onehealthtrust.org/publications/peer-reviewed-articles/the-lancet-series-on-antimicrobial-resistance-the-need-for-sustainable-access-to-effective-antibiotics/?gad_source=1&gad_campaignid=21377001781&gclid=CjwKCAjwuePGBhBZEiwAIGCVSx-ulkjSStFRfCZ_j9AkuPvlKuUcPI81Xbn_IurMVO037JXhD6htgBoCHWMQAvD_BwE',
      image:
        'https://medshadow.org/wp-content/uploads/2019/04/antibiotics-949x626.jpeg',
      alt: 'Antibiotics',
    },
    {
      category:
        'HEALTH SCIENCES • EDUCATION • MEDICAL FACTS',
      title:
        'Fascinating Facts About The Health Sciences',
      description:
        'Educational content with fascinating health science facts.',
      url:
        'https://shs.touro.edu/news/stories/25-fascinating-facts-about-the-health-sciences.php',
      image:
        'https://shs.touro.edu/media/schools-and-colleges/shs/images/stories/2020/SHS_July_Blog_3_InterestingFacts.jpg',
      alt: 'Health sciences',
    },
  ],

  [
    {
      category:
        "WOMEN'S HEALTH • REPRODUCTIVE HEALTH • HORMONES",
      title:
        "Is the birth control pill destroying women's sex drive?",
      description:
        "Investigation of birth control pills' impact on women's libido.",
      url:
        'https://www.abs-cbn.com/news/health-science/2025/9/26/is-the-birth-control-pill-destroying-women-s-sex-drive-1612',
      image:
        'https://od2-image-api.abs-cbn.com/prod/editorImage/1758874179237reproductive-health-supplies-coalition-fgqLiOKNjU8-unsplash.jpg',
      alt: 'Birth control and reproductive health',
    },
    {
      category:
        'MEDICAL NEWS • HEALTH RESEARCH • CURRENT EVENTS',
      title:
        'Cost of prescriptions rises year-on-year',
      description:
        'Recent health-related news coverage and developments.',
      url:
        'https://www.bbc.com/news/articles/czx0dypkpvko',
      image:
        'https://ichef.bbci.co.uk/news/800/cpsprodpb/68ad/live/1cfab9a0-8a6c-11f0-8ba8-9d1af6a803b3.jpg.webp',
      alt: 'Prescription medicines',
    },
    {
      category:
        'NUTRITION • SUPPLEMENTS • PREVENTIVE HEALTH',
      title:
        'Is There Really Any Benefit to Multivitamins?',
      description:
        'Evidence-based analysis of multivitamin health benefits.',
      url:
        'https://www.hopkinsmedicine.org/health/wellness-and-prevention/is-there-really-any-benefit-to-multivitamins',
      image:
        'https://media.istockphoto.com/id/1414489487/photo/supplements-and-vitamins-on-a-white-background-selective-focus.jpg?s=612x612&w=0&k=20&c=FgfT2r6_yRH8Rlx5R5MGu-rX3fgAMefkl2QSkd_JkSk=',
      alt: 'Multivitamins and supplements',
    },
  ],

  [
    {
      category:
        'MEDICATION MANAGEMENT • HEALTHCARE EDUCATION',
      title:
        'Taking Control of Your Medications',
      description:
        'Guide to safely organizing and tracking prescription medications.',
      url:
        'https://www.heart.org/en/health-topics/cardiac-rehab/managing-your-medicines/taking-control-of-your-medicines',
      image:
        'https://www.heart.org/-/media/Images/Health-Topics/High-Blood-Pressure/man-holding-pills-and-glass-of-water.jpg?h=667&w=1000&sc_lang=en',
      alt: 'Person holding medicine and a glass of water',
    },
    {
      category:
        'AGING • MENTAL HEALTH • NUTRITIONAL SUPPLEMENTS',
      title:
        'Can multivitamins improve mood and reduce stress in older adults?',
      description:
        'Study on multivitamins improving mood in older adults.',
      url:
        'https://www.news-medical.net/news/20241008/Can-multivitamins-improve-mood-and-reduce-stress-in-older-adults.aspx',
      image:
        'https://www.news-medical.net/image-handler/ts/20241008072446/ri/750/src/images/news/ImageForNews_792479_17283866854238540.jpg',
      alt: 'Multivitamins',
    },
    {
      category:
        'PEDIATRIC HEALTH • PARENTING • MEDICATION SAFETY',
      title:
        'How to get your child to take medicine',
      description:
        'Parent resource for safely giving medicine to toddlers.',
      url:
        'https://www.todaysparent.com/toddler/taking-medicine',
      image:
        'https://burtsrx.com/wp-content/uploads/2021/01/Getting-Your-Child-to-Take-Medication.jpg.webp',
      alt: 'Child taking medicine',
    },
  ],
]

const INTERVAL_MS = 7000

function prefersReducedMotion() {
  try {
    return window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
  } catch {
    return false
  }
}

export default function Carousel() {
  const headingId = useId()

  const [index, setIndex] = useState(0)

  const [playing, setPlaying] = useState(
    () => !prefersReducedMotion(),
  )

  const [hovering, setHovering] = useState(false)

  const [focusWithin, setFocusWithin] =
    useState(false)

  const go = useCallback((next) => {
    setIndex(
      (next + SLIDES.length) % SLIDES.length,
    )
  }, [])

  useEffect(() => {
    if (
      !playing ||
      hovering ||
      focusWithin
    ) {
      return undefined
    }

    const timer = window.setInterval(() => {
      setIndex(
        (current) =>
          (current + 1) % SLIDES.length,
      )
    }, INTERVAL_MS)

    return () => {
      window.clearInterval(timer)
    }
  }, [
    playing,
    hovering,
    focusWithin,
  ])

  return (
    <section
      className="home-section-block external-articles-section"
      aria-labelledby={headingId}
    >
      <div className="home-section-heading">
        <span>
          Health & Medicine
        </span>

        <h2 id={headingId}>
          Health Articles & Resources
        </h2>

        <p>
          Explore health, medicine, wellness, and
          medication-related articles from external
          health and news sources.
        </p>
      </div>

      <div
        className="external-carousel"
        role="region"
        aria-roledescription="carousel"
        aria-label="Health articles and resources"
        onMouseEnter={() =>
          setHovering(true)
        }
        onMouseLeave={() =>
          setHovering(false)
        }
        onFocus={() =>
          setFocusWithin(true)
        }
        onBlur={(event) => {
          if (
            !event.currentTarget.contains(
              event.relatedTarget,
            )
          ) {
            setFocusWithin(false)
          }
        }}
      >
        <div className="external-carousel__viewport">
          <div
            className="external-carousel__track"
            style={{
              transform: `translateX(-${index * 100}%)`,
            }}
            aria-live={
              playing &&
              !hovering &&
              !focusWithin
                ? 'off'
                : 'polite'
            }
          >
            {SLIDES.map(
              (slide, slideIndex) => (
                <div
                  key={`slide-${slideIndex}`}
                  className="external-carousel__slide"
                  role="group"
                  aria-roledescription="slide"
                  aria-label={`${
                    slideIndex + 1
                  } of ${SLIDES.length}`}
                  inert={
                    slideIndex !== index
                  }
                >
                  {slide.map(
                    (article) => (
                      <a
                        key={article.url}
                        href={article.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="external-article-card"
                        aria-label={`${article.title}. Opens external website in a new tab.`}
                      >
                        <div className="external-article-card__image">
                          <img
                            src={
                              article.image
                            }
                            alt={
                              article.alt
                            }
                            loading="lazy"
                            referrerPolicy="no-referrer"
                          />

                          <div className="external-article-card__source">
                            <i
                              className="bi bi-box-arrow-up-right"
                              aria-hidden="true"
                            ></i>

                            External article
                          </div>
                        </div>

                        <div className="external-article-card__overlay">
                          <div className="external-article-card__category">
                            {
                              article.category
                            }
                          </div>

                          <h3>
                            {article.title}
                          </h3>

                          <p>
                            {
                              article.description
                            }
                          </p>

                          <span className="external-article-card__read">
                            Read article

                            <i
                              className="bi bi-arrow-up-right"
                              aria-hidden="true"
                            ></i>
                          </span>
                        </div>
                      </a>
                    ),
                  )}
                </div>
              ),
            )}
          </div>
        </div>

        <button
          type="button"
          className="external-carousel__nav external-carousel__nav--prev"
          onClick={() =>
            go(index - 1)
          }
          aria-label="Previous articles"
        >
          <i
            className="bi bi-chevron-left"
            aria-hidden="true"
          ></i>
        </button>

        <button
          type="button"
          className="external-carousel__nav external-carousel__nav--next"
          onClick={() =>
            go(index + 1)
          }
          aria-label="Next articles"
        >
          <i
            className="bi bi-chevron-right"
            aria-hidden="true"
          ></i>
        </button>

        <div className="external-carousel__footer">
          <div className="external-carousel__indicators">
            {SLIDES.map(
              (_, slideIndex) => (
                <button
                  key={slideIndex}
                  type="button"
                  className={
                    slideIndex === index
                      ? 'external-carousel__indicator external-carousel__indicator--active'
                      : 'external-carousel__indicator'
                  }
                  onClick={() =>
                    go(slideIndex)
                  }
                  aria-label={`Show article group ${
                    slideIndex + 1
                  }`}
                  aria-current={
                    slideIndex === index
                      ? 'true'
                      : undefined
                  }
                ></button>
              ),
            )}
          </div>

          <button
            type="button"
            className="external-carousel__play"
            onClick={() =>
              setPlaying(
                (current) =>
                  !current,
              )
            }
            aria-label={
              playing
                ? 'Pause article carousel'
                : 'Start article carousel'
            }
          >
            <i
              className={
                playing
                  ? 'bi bi-pause-fill'
                  : 'bi bi-play-fill'
              }
              aria-hidden="true"
            ></i>

            {playing
              ? 'Pause'
              : 'Play'}
          </button>
        </div>
      </div>

      <p className="external-articles-note">
        <i
          className="bi bi-info-circle"
          aria-hidden="true"
        ></i>

        Articles open on third-party websites.
        PharmAssist does not control or endorse
        external content.
      </p>
    </section>
  )
}