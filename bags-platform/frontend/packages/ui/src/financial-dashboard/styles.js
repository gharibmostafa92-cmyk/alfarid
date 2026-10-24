// Isolated Tailwind preflight, original design styles, then utilities (matching the prototype cascade).
export const dashboardStyles = `:host{all:initial;display:block;min-height:100vh;direction:ltr;font-size:16px;font-weight:400;line-height:1.5;letter-spacing:normal;text-align:left;user-select:none;}*, ::before, ::after {
  --tw-border-spacing-x: 0;
  --tw-border-spacing-y: 0;
  --tw-translate-x: 0;
  --tw-translate-y: 0;
  --tw-rotate: 0;
  --tw-skew-x: 0;
  --tw-skew-y: 0;
  --tw-scale-x: 1;
  --tw-scale-y: 1;
  --tw-pan-x:  ;
  --tw-pan-y:  ;
  --tw-pinch-zoom:  ;
  --tw-scroll-snap-strictness: proximity;
  --tw-gradient-from-position:  ;
  --tw-gradient-via-position:  ;
  --tw-gradient-to-position:  ;
  --tw-ordinal:  ;
  --tw-slashed-zero:  ;
  --tw-numeric-figure:  ;
  --tw-numeric-spacing:  ;
  --tw-numeric-fraction:  ;
  --tw-ring-inset:  ;
  --tw-ring-offset-width: 0px;
  --tw-ring-offset-color: #fff;
  --tw-ring-color: rgb(59 130 246 / 0.5);
  --tw-ring-offset-shadow: 0 0 #0000;
  --tw-ring-shadow: 0 0 #0000;
  --tw-shadow: 0 0 #0000;
  --tw-shadow-colored: 0 0 #0000;
  --tw-blur:  ;
  --tw-brightness:  ;
  --tw-contrast:  ;
  --tw-grayscale:  ;
  --tw-hue-rotate:  ;
  --tw-invert:  ;
  --tw-saturate:  ;
  --tw-sepia:  ;
  --tw-drop-shadow:  ;
  --tw-backdrop-blur:  ;
  --tw-backdrop-brightness:  ;
  --tw-backdrop-contrast:  ;
  --tw-backdrop-grayscale:  ;
  --tw-backdrop-hue-rotate:  ;
  --tw-backdrop-invert:  ;
  --tw-backdrop-opacity:  ;
  --tw-backdrop-saturate:  ;
  --tw-backdrop-sepia:  ;
  --tw-contain-size:  ;
  --tw-contain-layout:  ;
  --tw-contain-paint:  ;
  --tw-contain-style:  ;
}

::backdrop {
  --tw-border-spacing-x: 0;
  --tw-border-spacing-y: 0;
  --tw-translate-x: 0;
  --tw-translate-y: 0;
  --tw-rotate: 0;
  --tw-skew-x: 0;
  --tw-skew-y: 0;
  --tw-scale-x: 1;
  --tw-scale-y: 1;
  --tw-pan-x:  ;
  --tw-pan-y:  ;
  --tw-pinch-zoom:  ;
  --tw-scroll-snap-strictness: proximity;
  --tw-gradient-from-position:  ;
  --tw-gradient-via-position:  ;
  --tw-gradient-to-position:  ;
  --tw-ordinal:  ;
  --tw-slashed-zero:  ;
  --tw-numeric-figure:  ;
  --tw-numeric-spacing:  ;
  --tw-numeric-fraction:  ;
  --tw-ring-inset:  ;
  --tw-ring-offset-width: 0px;
  --tw-ring-offset-color: #fff;
  --tw-ring-color: rgb(59 130 246 / 0.5);
  --tw-ring-offset-shadow: 0 0 #0000;
  --tw-ring-shadow: 0 0 #0000;
  --tw-shadow: 0 0 #0000;
  --tw-shadow-colored: 0 0 #0000;
  --tw-blur:  ;
  --tw-brightness:  ;
  --tw-contrast:  ;
  --tw-grayscale:  ;
  --tw-hue-rotate:  ;
  --tw-invert:  ;
  --tw-saturate:  ;
  --tw-sepia:  ;
  --tw-drop-shadow:  ;
  --tw-backdrop-blur:  ;
  --tw-backdrop-brightness:  ;
  --tw-backdrop-contrast:  ;
  --tw-backdrop-grayscale:  ;
  --tw-backdrop-hue-rotate:  ;
  --tw-backdrop-invert:  ;
  --tw-backdrop-opacity:  ;
  --tw-backdrop-saturate:  ;
  --tw-backdrop-sepia:  ;
  --tw-contain-size:  ;
  --tw-contain-layout:  ;
  --tw-contain-paint:  ;
  --tw-contain-style:  ;
}/*
! tailwindcss v3.4.19 | MIT License | https://tailwindcss.com
*//*
1. Prevent padding and border from affecting element width. (https://github.com/mozdevs/cssremedy/issues/4)
2. Allow adding a border to an element by just adding a border-width. (https://github.com/tailwindcss/tailwindcss/pull/116)
*/

*,
::before,
::after {
  box-sizing: border-box; /* 1 */
  border-width: 0; /* 2 */
  border-style: solid; /* 2 */
  border-color: #e5e7eb; /* 2 */
}

::before,
::after {
  --tw-content: '';
}

html,
:host {
  line-height: 1.5; /* 1 */
  -webkit-text-size-adjust: 100%; /* 2 */
  -moz-tab-size: 4; /* 3 */
  tab-size: 4; /* 3 */
  font-family: ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"; /* 4 */
  font-feature-settings: normal; /* 5 */
  font-variation-settings: normal; /* 6 */
  -webkit-tap-highlight-color: transparent; /* 7 */
}

body {
  margin: 0; /* 1 */
  line-height: inherit; /* 2 */
}

hr {
  height: 0; /* 1 */
  color: inherit; /* 2 */
  border-top-width: 1px; /* 3 */
}

abbr:where([title]) {
  text-decoration: underline dotted;
}

h1,
h2,
h3,
h4,
h5,
h6 {
  font-size: inherit;
  font-weight: inherit;
}

a {
  color: inherit;
  text-decoration: inherit;
}

b,
strong {
  font-weight: bolder;
}

code,
kbd,
samp,
pre {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; /* 1 */
  font-feature-settings: normal; /* 2 */
  font-variation-settings: normal; /* 3 */
  font-size: 1em; /* 4 */
}

small {
  font-size: 80%;
}

sub,
sup {
  font-size: 75%;
  line-height: 0;
  position: relative;
  vertical-align: baseline;
}

sub {
  bottom: -0.25em;
}

sup {
  top: -0.5em;
}

table {
  text-indent: 0; /* 1 */
  border-color: inherit; /* 2 */
  border-collapse: collapse; /* 3 */
}

button,
input,
optgroup,
select,
textarea {
  font-family: inherit; /* 1 */
  font-feature-settings: inherit; /* 1 */
  font-variation-settings: inherit; /* 1 */
  font-size: 100%; /* 1 */
  font-weight: inherit; /* 1 */
  line-height: inherit; /* 1 */
  letter-spacing: inherit; /* 1 */
  color: inherit; /* 1 */
  margin: 0; /* 2 */
  padding: 0; /* 3 */
}

button,
select {
  text-transform: none;
}

button,
input:where([type='button']),
input:where([type='reset']),
input:where([type='submit']) {
  -webkit-appearance: button; /* 1 */
  background-color: transparent; /* 2 */
  background-image: none; /* 2 */
}

:-moz-focusring {
  outline: auto;
}

:-moz-ui-invalid {
  box-shadow: none;
}

progress {
  vertical-align: baseline;
}

::-webkit-inner-spin-button,
::-webkit-outer-spin-button {
  height: auto;
}

[type='search'] {
  -webkit-appearance: textfield; /* 1 */
  outline-offset: -2px; /* 2 */
}

::-webkit-search-decoration {
  -webkit-appearance: none;
}

::-webkit-file-upload-button {
  -webkit-appearance: button; /* 1 */
  font: inherit; /* 2 */
}

summary {
  display: list-item;
}

blockquote,
dl,
dd,
h1,
h2,
h3,
h4,
h5,
h6,
hr,
figure,
p,
pre {
  margin: 0;
}

fieldset {
  margin: 0;
  padding: 0;
}

legend {
  padding: 0;
}

ol,
ul,
menu {
  list-style: none;
  margin: 0;
  padding: 0;
}

dialog {
  padding: 0;
}

textarea {
  resize: vertical;
}

input::placeholder,
textarea::placeholder {
  opacity: 1; /* 1 */
  color: #9ca3af; /* 2 */
}

button,
[role="button"] {
  cursor: pointer;
}

:disabled {
  cursor: default;
}

img,
svg,
video,
canvas,
audio,
iframe,
embed,
object {
  display: block; /* 1 */
  vertical-align: middle; /* 2 */
}

img,
video {
  max-width: 100%;
  height: auto;
}

[hidden]:where(:not([hidden="until-found"])) {
  display: none;
}

    :host,
    .theme-light {
      --bg-canvas: #f2eff3;
      --bg-card: #ffffff;
      --bg-subcard: #f5f2f6;
      --bg-subcard-hover: #ebe6ed;
      --seg-active-bg: #ffffff;
      --offer-icon-bg: #ffffff;
      --offer-active-bg: #fff9f7;
      --text-primary: #18151c;
      --text-secondary: #5c5663;
      --text-muted: #706a78;
      --border-subtle: rgba(24, 21, 28, 0.06);
      --divider-color: rgba(24, 21, 28, 0.06);
      --pill-active-bg: #18151c;
      --pill-active-text: #ffffff;
      --accent-orange: #e0491f;
      --accent-orange-light: #fde8e1;
      --bar-loss: #18151c;
      --badge-green-bg: #dcfce7;
      --badge-green-text: #15803d;
      --badge-red-bg: #ffe4e6;
      --badge-red-text: #e11d48;
      --icon-well-bg: rgba(24, 21, 28, 0.05);
      --toggle-off-bg: rgba(24, 21, 28, 0.12);
      --theme-active-circle: #f3eff5;
      --hatched-track: repeating-linear-gradient(
        -60deg,
        rgba(24, 21, 28, 0.14) 0px,
        rgba(24, 21, 28, 0.14) 1.5px,
        transparent 1.5px,
        transparent 5px
      );
    }

    :host([data-theme="dark"]),
    .theme-dark {
      --bg-canvas: #100d13;
      --bg-card: #17141a;
      --bg-subcard: #1c1920;
      --bg-subcard-hover: #25212a;
      --seg-active-bg: #26222c;
      --offer-icon-bg: #252129;
      --offer-active-bg: #1e1618;
      --text-primary: #f5f3f7;
      --text-secondary: #b0abb8;
      --text-muted: #8e8896;
      --border-subtle: rgba(255, 255, 255, 0.07);
      --divider-color: rgba(255, 255, 255, 0.06);
      --pill-active-bg: #f5f3f7;
      --pill-active-text: #141117;
      --accent-orange: #e65328;
      --accent-orange-light: rgba(230, 83, 40, 0.18);
      --bar-loss: #2c2832;
      --badge-green-bg: rgba(6, 78, 59, 0.45);
      --badge-green-text: #34d399;
      --badge-red-bg: rgba(136, 19, 55, 0.45);
      --badge-red-text: #fb7185;
      --icon-well-bg: rgba(255, 255, 255, 0.06);
      --toggle-off-bg: rgba(255, 255, 255, 0.15);
      --theme-active-circle: #26222c;
      --hatched-track: repeating-linear-gradient(
        -60deg,
        rgba(255, 255, 255, 0.16) 0px,
        rgba(255, 255, 255, 0.16) 1.5px,
        transparent 1.5px,
        transparent 5px
      );
    }

    * {
      box-sizing: border-box;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    :host {
      margin: 0;
      padding: 0;
      font-family: 'Inter', 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
      background-color: var(--bg-canvas);
      color: var(--text-primary);
      overflow-x: hidden;
    }

    h1, h2, h3, .font-display {
      font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
    }

    /* Live Theme Viewport & Underlay Snapshot for 100% True Circular Reveal */
    #app-viewport {
      position: relative;
      z-index: 10;
      background-color: var(--bg-canvas);
      color: var(--text-primary);
      min-height: 100vh;
      will-change: clip-path;
    }

    #theme-wipe-overlay {
      position: fixed;
      inset: 0;
      z-index: 1;
      pointer-events: none;
      overflow: hidden;
    }

    #theme-wipe-overlay *,
    #theme-wipe-overlay {
      animation: none !important;
      transition: none !important;
    }

    .no-theme-transitions,
    .no-theme-transitions * {
      transition: none !important;
    }

    /* Surface classes */
    .v-card {
      background-color: var(--bg-card);
      border-radius: 22px;
      border: 1px solid var(--border-subtle);
    }

    .v-subcard {
      background-color: var(--bg-subcard);
      border-radius: 16px;
    }

    .v-pill-surface {
      background-color: var(--bg-card);
      border: 1px solid var(--border-subtle);
    }

    .v-badge-green {
      background-color: var(--badge-green-bg);
      color: var(--badge-green-text);
    }

    .v-badge-red {
      background-color: var(--badge-red-bg);
      color: var(--badge-red-text);
    }

    .v-icon-well {
      background-color: var(--icon-well-bg);
      color: var(--text-secondary);
    }

    .v-divide > :not([hidden]) ~ :not([hidden]) {
      border-top: 1px solid var(--divider-color);
    }

    .v-border-b {
      border-bottom: 1px solid var(--divider-color);
    }

    .v-border-r {
      border-right: 1px solid var(--divider-color);
    }

    /* Orange Gradient Hero Cards (Total Earnings & Rewards Card) */
    .v-orange-card {
      background: radial-gradient(circle at 78% 42%, #f48668 0%, #e7582f 48%, #dc4318 100%);
      color: #ffffff;
    }

    .v-orange-earnings {
      background: radial-gradient(circle at 22% 18%, #f4886b 0%, #e6542b 55%, #db4218 100%);
      color: #ffffff;
    }

    /* Profit Hatched Orange Bar */
    .bar-profit-hatched {
      background: repeating-linear-gradient(
        -45deg,
        #e0491f 0px,
        #e0491f 2.5px,
        #f37c59 2.5px,
        #f37c59 5px
      );
    }

    /* White Hatched Progress Bar on Rewards Card */
    .rewards-hatched-track {
      background: repeating-linear-gradient(
        -60deg,
        rgba(255, 255, 255, 0.45) 0px,
        rgba(255, 255, 255, 0.45) 1.5px,
        transparent 1.5px,
        transparent 5.5px
      );
    }

    /* Grey Hatched Progress Bar on Spending Limit */
    .spending-hatched-track {
      background: var(--hatched-track);
    }

    /* Staggered Blur-In Animation matching video transitions */
    @keyframes blurFadeIn {
      0% {
        opacity: 0;
        filter: blur(10px);
        transform: translateY(8px);
      }
      100% {
        opacity: 1;
        filter: blur(0px);
        transform: translateY(0px);
      }
    }

    .stagger-item {
      animation: blurFadeIn 0.48s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .stagger-1 { animation-delay: 0.03s; }
    .stagger-2 { animation-delay: 0.09s; }
    .stagger-3 { animation-delay: 0.15s; }
    .stagger-4 { animation-delay: 0.21s; }
    .stagger-5 { animation-delay: 0.27s; }

    /* 3D Credit Card Flip */
    .card-3d-perspective {
      perspective: 1100px;
    }
    .card-3d-inner {
      position: relative;
      width: 100%;
      height: 100%;
      transform-style: preserve-3d;
      transition: transform 0.65s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .card-3d-inner.is-flipped {
      transform: rotateY(180deg);
    }
    .card-3d-face {
      position: absolute;
      inset: 0;
      backface-visibility: hidden;
      -webkit-backface-visibility: hidden;
      border-radius: 18px;
      overflow: hidden;
    }
    .card-3d-back {
      transform: rotateY(180deg);
    }

    /* Custom Range Slider for Manage Card Controls */
    input[type="range"].v-slider {
      -webkit-appearance: none;
      appearance: none;
      width: 100%;
      height: 5px;
      border-radius: 999px;
      outline: none;
      cursor: pointer;
    }
    input[type="range"].v-slider::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #ffffff;
      border: 3.5px solid #e0491f;
      box-shadow: 0 2px 6px rgba(224, 73, 31, 0.35);
      cursor: grab;
      transition: transform 0.15s ease;
    }
    input[type="range"].v-slider::-webkit-slider-thumb:active {
      cursor: grabbing;
      transform: scale(1.12);
    }

    /* Custom Scrollbar */
    ::-webkit-scrollbar {
      width: 5px;
      height: 5px;
    }
    ::-webkit-scrollbar-track {
      background: transparent;
    }
    ::-webkit-scrollbar-thumb {
      background: rgba(150, 145, 160, 0.25);
      border-radius: 999px;
    }
  
.container {
  width: 100%;
}
@media (min-width: 640px) {

  .container {
    max-width: 640px;
  }
}
@media (min-width: 768px) {

  .container {
    max-width: 768px;
  }
}
@media (min-width: 1024px) {

  .container {
    max-width: 1024px;
  }
}
@media (min-width: 1280px) {

  .container {
    max-width: 1280px;
  }
}
@media (min-width: 1536px) {

  .container {
    max-width: 1536px;
  }
}
.pointer-events-none {
  pointer-events: none;
}
.fixed {
  position: fixed;
}
.absolute {
  position: absolute;
}
.relative {
  position: relative;
}
.inset-0 {
  inset: 0px;
}
.inset-x-0 {
  left: 0px;
  right: 0px;
}
.-bottom-0\\.5 {
  bottom: -0.125rem;
}
.-right-0\\.5 {
  right: -0.125rem;
}
.-top-0\\.5 {
  top: -0.125rem;
}
.-top-8 {
  top: -2rem;
}
.bottom-5 {
  bottom: 1.25rem;
}
.bottom-6 {
  bottom: 1.5rem;
}
.left-0 {
  left: 0px;
}
.right-6 {
  right: 1.5rem;
}
.top-0 {
  top: 0px;
}
.z-10 {
  z-index: 10;
}
.z-20 {
  z-index: 20;
}
.z-50 {
  z-index: 50;
}
.mx-auto {
  margin-left: auto;
  margin-right: auto;
}
.my-3 {
  margin-top: 0.75rem;
  margin-bottom: 0.75rem;
}
.my-6 {
  margin-top: 1.5rem;
  margin-bottom: 1.5rem;
}
.-ml-2\\.5 {
  margin-left: -0.625rem;
}
.mb-1 {
  margin-bottom: 0.25rem;
}
.mb-2 {
  margin-bottom: 0.5rem;
}
.mb-3 {
  margin-bottom: 0.75rem;
}
.mb-3\\.5 {
  margin-bottom: 0.875rem;
}
.mb-4 {
  margin-bottom: 1rem;
}
.mb-6 {
  margin-bottom: 1.5rem;
}
.ml-1 {
  margin-left: 0.25rem;
}
.ml-auto {
  margin-left: auto;
}
.mt-0\\.5 {
  margin-top: 0.125rem;
}
.mt-1 {
  margin-top: 0.25rem;
}
.mt-1\\.5 {
  margin-top: 0.375rem;
}
.mt-10 {
  margin-top: 2.5rem;
}
.mt-2 {
  margin-top: 0.5rem;
}
.mt-3 {
  margin-top: 0.75rem;
}
.mt-3\\.5 {
  margin-top: 0.875rem;
}
.mt-4 {
  margin-top: 1rem;
}
.mt-5 {
  margin-top: 1.25rem;
}
.inline-block {
  display: inline-block;
}
.flex {
  display: flex;
}
.inline-flex {
  display: inline-flex;
}
.table {
  display: table;
}
.grid {
  display: grid;
}
.hidden {
  display: none;
}
.h-1\\.5 {
  height: 0.375rem;
}
.h-10 {
  height: 2.5rem;
}
.h-2 {
  height: 0.5rem;
}
.h-2\\.5 {
  height: 0.625rem;
}
.h-24 {
  height: 6rem;
}
.h-3 {
  height: 0.75rem;
}
.h-3\\.5 {
  height: 0.875rem;
}
.h-4 {
  height: 1rem;
}
.h-5 {
  height: 1.25rem;
}
.h-6 {
  height: 1.5rem;
}
.h-7 {
  height: 1.75rem;
}
.h-8 {
  height: 2rem;
}
.h-9 {
  height: 2.25rem;
}
.h-\\[16px\\] {
  height: 16px;
}
.h-\\[17px\\] {
  height: 17px;
}
.h-\\[190px\\] {
  height: 190px;
}
.h-\\[202px\\] {
  height: 202px;
}
.h-full {
  height: 100%;
}
.max-h-\\[320px\\] {
  max-height: 320px;
}
.min-h-\\[168px\\] {
  min-height: 168px;
}
.min-h-\\[470px\\] {
  min-height: 470px;
}
.min-h-screen {
  min-height: 100vh;
}
.w-1 {
  width: 0.25rem;
}
.w-1\\.5 {
  width: 0.375rem;
}
.w-11 {
  width: 2.75rem;
}
.w-2 {
  width: 0.5rem;
}
.w-2\\.5 {
  width: 0.625rem;
}
.w-24 {
  width: 6rem;
}
.w-28 {
  width: 7rem;
}
.w-3 {
  width: 0.75rem;
}
.w-3\\.5 {
  width: 0.875rem;
}
.w-4 {
  width: 1rem;
}
.w-5 {
  width: 1.25rem;
}
.w-6 {
  width: 1.5rem;
}
.w-7 {
  width: 1.75rem;
}
.w-8 {
  width: 2rem;
}
.w-9 {
  width: 2.25rem;
}
.w-\\[16px\\] {
  width: 16px;
}
.w-\\[17px\\] {
  width: 17px;
}
.w-\\[44px\\] {
  width: 44px;
}
.w-full {
  width: 100%;
}
.min-w-0 {
  min-width: 0px;
}
.max-w-\\[1360px\\] {
  max-width: 1360px;
}
.max-w-\\[26px\\] {
  max-width: 26px;
}
.max-w-\\[330px\\] {
  max-width: 330px;
}
.max-w-\\[360px\\] {
  max-width: 360px;
}
.max-w-md {
  max-width: 28rem;
}
.flex-1 {
  flex: 1 1 0%;
}
.shrink-0 {
  flex-shrink: 0;
}
.border-collapse {
  border-collapse: collapse;
}
.translate-y-0 {
  --tw-translate-y: 0px;
  transform: translate(var(--tw-translate-x), var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));
}
.translate-y-2 {
  --tw-translate-y: 0.5rem;
  transform: translate(var(--tw-translate-x), var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));
}
.rotate-12 {
  --tw-rotate: 12deg;
  transform: translate(var(--tw-translate-x), var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));
}
.transform {
  transform: translate(var(--tw-translate-x), var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));
}
.cursor-pointer {
  cursor: pointer;
}
.select-none {
  user-select: none;
}
.grid-cols-1 {
  grid-template-columns: repeat(1, minmax(0, 1fr));
}
.grid-cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.grid-cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.grid-cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
.flex-col {
  flex-direction: column;
}
.flex-wrap {
  flex-wrap: wrap;
}
.items-start {
  align-items: flex-start;
}
.items-end {
  align-items: flex-end;
}
.items-center {
  align-items: center;
}
.items-baseline {
  align-items: baseline;
}
.justify-start {
  justify-content: flex-start;
}
.justify-end {
  justify-content: flex-end;
}
.justify-center {
  justify-content: center;
}
.justify-between {
  justify-content: space-between;
}
.gap-0\\.5 {
  gap: 0.125rem;
}
.gap-1 {
  gap: 0.25rem;
}
.gap-1\\.5 {
  gap: 0.375rem;
}
.gap-2 {
  gap: 0.5rem;
}
.gap-2\\.5 {
  gap: 0.625rem;
}
.gap-3 {
  gap: 0.75rem;
}
.gap-3\\.5 {
  gap: 0.875rem;
}
.gap-4 {
  gap: 1rem;
}
.gap-5 {
  gap: 1.25rem;
}
.gap-6 {
  gap: 1.5rem;
}
.gap-\\[3px\\] {
  gap: 3px;
}
.space-y-2 > :not([hidden]) ~ :not([hidden]) {
  --tw-space-y-reverse: 0;
  margin-top: calc(0.5rem * calc(1 - var(--tw-space-y-reverse)));
  margin-bottom: calc(0.5rem * var(--tw-space-y-reverse));
}
.space-y-3 > :not([hidden]) ~ :not([hidden]) {
  --tw-space-y-reverse: 0;
  margin-top: calc(0.75rem * calc(1 - var(--tw-space-y-reverse)));
  margin-bottom: calc(0.75rem * var(--tw-space-y-reverse));
}
.space-y-4 > :not([hidden]) ~ :not([hidden]) {
  --tw-space-y-reverse: 0;
  margin-top: calc(1rem * calc(1 - var(--tw-space-y-reverse)));
  margin-bottom: calc(1rem * var(--tw-space-y-reverse));
}
.space-y-5 > :not([hidden]) ~ :not([hidden]) {
  --tw-space-y-reverse: 0;
  margin-top: calc(1.25rem * calc(1 - var(--tw-space-y-reverse)));
  margin-bottom: calc(1.25rem * var(--tw-space-y-reverse));
}
.self-start {
  align-self: flex-start;
}
.self-center {
  align-self: center;
}
.overflow-hidden {
  overflow: hidden;
}
.overflow-x-auto {
  overflow-x: auto;
}
.overflow-y-auto {
  overflow-y: auto;
}
.truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.whitespace-nowrap {
  white-space: nowrap;
}
.\\!rounded-\\[13px\\] {
  border-radius: 13px !important;
}
.rounded {
  border-radius: 0.25rem;
}
.rounded-2xl {
  border-radius: 1rem;
}
.rounded-\\[18px\\] {
  border-radius: 18px;
}
.rounded-\\[22px\\] {
  border-radius: 22px;
}
.rounded-\\[3px\\] {
  border-radius: 3px;
}
.rounded-\\[6px\\] {
  border-radius: 6px;
}
.rounded-full {
  border-radius: 9999px;
}
.rounded-lg {
  border-radius: 0.5rem;
}
.rounded-md {
  border-radius: 0.375rem;
}
.rounded-xl {
  border-radius: 0.75rem;
}
.rounded-l-xl {
  border-top-left-radius: 0.75rem;
  border-bottom-left-radius: 0.75rem;
}
.rounded-r-full {
  border-top-right-radius: 9999px;
  border-bottom-right-radius: 9999px;
}
.rounded-r-xl {
  border-top-right-radius: 0.75rem;
  border-bottom-right-radius: 0.75rem;
}
.border {
  border-width: 1px;
}
.border-8 {
  border-width: 8px;
}
.border-dashed {
  border-style: dashed;
}
.border-black\\/5 {
  border-color: rgb(0 0 0 / 0.05);
}
.border-gray-300 {
  --tw-border-opacity: 1;
  border-color: rgb(209 213 219 / var(--tw-border-opacity, 1));
}
.border-transparent {
  border-color: transparent;
}
.border-white {
  --tw-border-opacity: 1;
  border-color: rgb(255 255 255 / var(--tw-border-opacity, 1));
}
.bg-\\[\\#18151c\\] {
  --tw-bg-opacity: 1;
  background-color: rgb(24 21 28 / var(--tw-bg-opacity, 1));
}
.bg-\\[\\#e0491f\\] {
  --tw-bg-opacity: 1;
  background-color: rgb(224 73 31 / var(--tw-bg-opacity, 1));
}
.bg-\\[\\#eb001b\\] {
  --tw-bg-opacity: 1;
  background-color: rgb(235 0 27 / var(--tw-bg-opacity, 1));
}
.bg-\\[\\#f4e3d7\\] {
  --tw-bg-opacity: 1;
  background-color: rgb(244 227 215 / var(--tw-bg-opacity, 1));
}
.bg-\\[\\#f79e1b\\] {
  --tw-bg-opacity: 1;
  background-color: rgb(247 158 27 / var(--tw-bg-opacity, 1));
}
.bg-amber-500 {
  --tw-bg-opacity: 1;
  background-color: rgb(245 158 11 / var(--tw-bg-opacity, 1));
}
.bg-black\\/40 {
  background-color: rgb(0 0 0 / 0.4);
}
.bg-black\\/80 {
  background-color: rgb(0 0 0 / 0.8);
}
.bg-emerald-500 {
  --tw-bg-opacity: 1;
  background-color: rgb(16 185 129 / var(--tw-bg-opacity, 1));
}
.bg-sky-100 {
  --tw-bg-opacity: 1;
  background-color: rgb(224 242 254 / var(--tw-bg-opacity, 1));
}
.bg-transparent {
  background-color: transparent;
}
.bg-white {
  --tw-bg-opacity: 1;
  background-color: rgb(255 255 255 / var(--tw-bg-opacity, 1));
}
.bg-white\\/15 {
  background-color: rgb(255 255 255 / 0.15);
}
.bg-white\\/20 {
  background-color: rgb(255 255 255 / 0.2);
}
.p-0\\.5 {
  padding: 0.125rem;
}
.p-1 {
  padding: 0.25rem;
}
.p-1\\.5 {
  padding: 0.375rem;
}
.p-2\\.5 {
  padding: 0.625rem;
}
.p-3 {
  padding: 0.75rem;
}
.p-3\\.5 {
  padding: 0.875rem;
}
.p-4 {
  padding: 1rem;
}
.p-5 {
  padding: 1.25rem;
}
.p-6 {
  padding: 1.5rem;
}
.p-7 {
  padding: 1.75rem;
}
.px-1 {
  padding-left: 0.25rem;
  padding-right: 0.25rem;
}
.px-1\\.5 {
  padding-left: 0.375rem;
  padding-right: 0.375rem;
}
.px-2 {
  padding-left: 0.5rem;
  padding-right: 0.5rem;
}
.px-2\\.5 {
  padding-left: 0.625rem;
  padding-right: 0.625rem;
}
.px-3 {
  padding-left: 0.75rem;
  padding-right: 0.75rem;
}
.px-3\\.5 {
  padding-left: 0.875rem;
  padding-right: 0.875rem;
}
.px-4 {
  padding-left: 1rem;
  padding-right: 1rem;
}
.px-5 {
  padding-left: 1.25rem;
  padding-right: 1.25rem;
}
.px-6 {
  padding-left: 1.5rem;
  padding-right: 1.5rem;
}
.py-0\\.5 {
  padding-top: 0.125rem;
  padding-bottom: 0.125rem;
}
.py-1 {
  padding-top: 0.25rem;
  padding-bottom: 0.25rem;
}
.py-1\\.5 {
  padding-top: 0.375rem;
  padding-bottom: 0.375rem;
}
.py-2 {
  padding-top: 0.5rem;
  padding-bottom: 0.5rem;
}
.py-2\\.5 {
  padding-top: 0.625rem;
  padding-bottom: 0.625rem;
}
.py-3 {
  padding-top: 0.75rem;
  padding-bottom: 0.75rem;
}
.py-3\\.5 {
  padding-top: 0.875rem;
  padding-bottom: 0.875rem;
}
.py-4 {
  padding-top: 1rem;
  padding-bottom: 1rem;
}
.pb-3\\.5 {
  padding-bottom: 0.875rem;
}
.pb-4 {
  padding-bottom: 1rem;
}
.pb-5 {
  padding-bottom: 1.25rem;
}
.pb-8 {
  padding-bottom: 2rem;
}
.pl-3\\.5 {
  padding-left: 0.875rem;
}
.pr-0 {
  padding-right: 0px;
}
.pr-1 {
  padding-right: 0.25rem;
}
.pr-2 {
  padding-right: 0.5rem;
}
.pr-4 {
  padding-right: 1rem;
}
.pr-5 {
  padding-right: 1.25rem;
}
.pt-1 {
  padding-top: 0.25rem;
}
.pt-3 {
  padding-top: 0.75rem;
}
.pt-5 {
  padding-top: 1.25rem;
}
.text-left {
  text-align: left;
}
.text-right {
  text-align: right;
}
.font-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}
.text-\\[10\\.5px\\] {
  font-size: 10.5px;
}
.text-\\[10px\\] {
  font-size: 10px;
}
.text-\\[11\\.5px\\] {
  font-size: 11.5px;
}
.text-\\[11px\\] {
  font-size: 11px;
}
.text-\\[12\\.5px\\] {
  font-size: 12.5px;
}
.text-\\[12px\\] {
  font-size: 12px;
}
.text-\\[13\\.5px\\] {
  font-size: 13.5px;
}
.text-\\[13px\\] {
  font-size: 13px;
}
.text-\\[14px\\] {
  font-size: 14px;
}
.text-\\[15px\\] {
  font-size: 15px;
}
.text-\\[16px\\] {
  font-size: 16px;
}
.text-\\[17px\\] {
  font-size: 17px;
}
.text-\\[18px\\] {
  font-size: 18px;
}
.text-\\[32px\\] {
  font-size: 32px;
}
.text-\\[36px\\] {
  font-size: 36px;
}
.text-\\[46px\\] {
  font-size: 46px;
}
.text-\\[9\\.5px\\] {
  font-size: 9.5px;
}
.text-\\[9px\\] {
  font-size: 9px;
}
.text-base {
  font-size: 1rem;
  line-height: 1.5rem;
}
.text-sm {
  font-size: 0.875rem;
  line-height: 1.25rem;
}
.text-xs {
  font-size: 0.75rem;
  line-height: 1rem;
}
.font-bold {
  font-weight: 700;
}
.font-medium {
  font-weight: 500;
}
.font-semibold {
  font-weight: 600;
}
.uppercase {
  text-transform: uppercase;
}
.italic {
  font-style: italic;
}
.leading-none {
  line-height: 1;
}
.leading-relaxed {
  line-height: 1.625;
}
.leading-tight {
  line-height: 1.25;
}
.tracking-\\[-0\\.025em\\] {
  letter-spacing: -0.025em;
}
.tracking-tight {
  letter-spacing: -0.025em;
}
.tracking-wider {
  letter-spacing: 0.05em;
}
.tracking-widest {
  letter-spacing: 0.1em;
}
.text-\\[\\#18151c\\] {
  --tw-text-opacity: 1;
  color: rgb(24 21 28 / var(--tw-text-opacity, 1));
}
.text-\\[\\#e0491f\\] {
  --tw-text-opacity: 1;
  color: rgb(224 73 31 / var(--tw-text-opacity, 1));
}
.text-amber-500 {
  --tw-text-opacity: 1;
  color: rgb(245 158 11 / var(--tw-text-opacity, 1));
}
.text-black {
  --tw-text-opacity: 1;
  color: rgb(0 0 0 / var(--tw-text-opacity, 1));
}
.text-emerald-500 {
  --tw-text-opacity: 1;
  color: rgb(16 185 129 / var(--tw-text-opacity, 1));
}
.text-rose-500 {
  --tw-text-opacity: 1;
  color: rgb(244 63 94 / var(--tw-text-opacity, 1));
}
.text-sky-700 {
  --tw-text-opacity: 1;
  color: rgb(3 105 161 / var(--tw-text-opacity, 1));
}
.text-sky-800 {
  --tw-text-opacity: 1;
  color: rgb(7 89 133 / var(--tw-text-opacity, 1));
}
.text-white {
  --tw-text-opacity: 1;
  color: rgb(255 255 255 / var(--tw-text-opacity, 1));
}
.text-white\\/50 {
  color: rgb(255 255 255 / 0.5);
}
.text-white\\/75 {
  color: rgb(255 255 255 / 0.75);
}
.text-white\\/80 {
  color: rgb(255 255 255 / 0.8);
}
.text-white\\/85 {
  color: rgb(255 255 255 / 0.85);
}
.text-white\\/90 {
  color: rgb(255 255 255 / 0.9);
}
.antialiased {
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
.accent-\\[\\#e0491f\\] {
  accent-color: #e0491f;
}
.opacity-0 {
  opacity: 0;
}
.opacity-100 {
  opacity: 1;
}
.opacity-60 {
  opacity: 0.6;
}
.opacity-\\[0\\.04\\] {
  opacity: 0.04;
}
.mix-blend-screen {
  mix-blend-mode: screen;
}
.shadow-2xl {
  --tw-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
  --tw-shadow-colored: 0 25px 50px -12px var(--tw-shadow-color);
  box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}
.shadow-inner {
  --tw-shadow: inset 0 2px 4px 0 rgb(0 0 0 / 0.05);
  --tw-shadow-colored: inset 0 2px 4px 0 var(--tw-shadow-color);
  box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}
.shadow-lg {
  --tw-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
  --tw-shadow-colored: 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color);
  box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}
.shadow-md {
  --tw-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
  --tw-shadow-colored: 0 4px 6px -1px var(--tw-shadow-color), 0 2px 4px -2px var(--tw-shadow-color);
  box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}
.shadow-xl {
  --tw-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
  --tw-shadow-colored: 0 20px 25px -5px var(--tw-shadow-color), 0 8px 10px -6px var(--tw-shadow-color);
  box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), var(--tw-shadow);
}
.outline-none {
  outline: 2px solid transparent;
  outline-offset: 2px;
}
.outline {
  outline-style: solid;
}
.blur {
  --tw-blur: blur(8px);
  filter: var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow);
}
.grayscale {
  --tw-grayscale: grayscale(100%);
  filter: var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow);
}
.filter {
  filter: var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow);
}
.transition {
  transition-property: color, background-color, border-color, text-decoration-color, fill, stroke, opacity, box-shadow, transform, filter, backdrop-filter;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}
.transition-all {
  transition-property: all;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}
.transition-colors {
  transition-property: color, background-color, border-color, text-decoration-color, fill, stroke;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}
.transition-opacity {
  transition-property: opacity;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}
.duration-150 {
  transition-duration: 150ms;
}
.duration-200 {
  transition-duration: 200ms;
}
.duration-300 {
  transition-duration: 300ms;
}
.duration-500 {
  transition-duration: 500ms;
}
.hover\\:bg-white\\/95:hover {
  background-color: rgb(255 255 255 / 0.95);
}
.hover\\:underline:hover {
  text-decoration-line: underline;
}
.hover\\:opacity-100:hover {
  opacity: 1;
}
.hover\\:opacity-75:hover {
  opacity: 0.75;
}
.hover\\:opacity-80:hover {
  opacity: 0.8;
}
.hover\\:opacity-85:hover {
  opacity: 0.85;
}
.hover\\:opacity-90:hover {
  opacity: 0.9;
}
.hover\\:opacity-95:hover {
  opacity: 0.95;
}
.group:hover .group-hover\\:opacity-100 {
  opacity: 1;
}
@media (min-width: 640px) {

  .sm\\:block {
    display: block;
  }

  .sm\\:w-36 {
    width: 9rem;
  }

  .sm\\:grid-cols-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .sm\\:p-5 {
    padding: 1.25rem;
  }

  .sm\\:p-7 {
    padding: 1.75rem;
  }

  .sm\\:px-7 {
    padding-left: 1.75rem;
    padding-right: 1.75rem;
  }

  .sm\\:text-\\[40px\\] {
    font-size: 40px;
  }

  .sm\\:text-\\[52px\\] {
    font-size: 52px;
  }
}
@media (min-width: 768px) {

  .md\\:flex {
    display: flex;
  }

  .md\\:grid-cols-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
@media (min-width: 1024px) {

  .lg\\:col-span-4 {
    grid-column: span 4 / span 4;
  }

  .lg\\:col-span-5 {
    grid-column: span 5 / span 5;
  }

  .lg\\:col-span-6 {
    grid-column: span 6 / span 6;
  }

  .lg\\:col-span-7 {
    grid-column: span 7 / span 7;
  }

  .lg\\:col-span-8 {
    grid-column: span 8 / span 8;
  }

  .lg\\:grid-cols-12 {
    grid-template-columns: repeat(12, minmax(0, 1fr));
  }

  .lg\\:pb-0 {
    padding-bottom: 0px;
  }

  .lg\\:pr-4 {
    padding-right: 1rem;
  }
}
/* ==================== MOBILE-FIRST & ONE-HANDED UX ENHANCEMENTS ==================== */
:host button:focus-visible,
:host input:focus-visible,
:host [role="button"]:focus-visible {
  outline: 2px solid var(--accent-orange);
  outline-offset: 2px;
}

.v-touch-icon-btn {
  min-width: 44px;
  min-height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  transition: background-color 150ms ease, opacity 150ms ease, transform 100ms ease;
}
.v-touch-icon-btn:active {
  transform: scale(0.95);
}

.v-action-cta {
  min-height: 48px;
  transition: transform 100ms ease, opacity 150ms ease, background-color 150ms ease;
}
.v-action-cta:active {
  transform: scale(0.98);
}

.v-side-group {
  width: 48px;
}
.v-aside-nav .side-nav-btn,
.v-aside-nav .theme-btn-light,
.v-aside-nav .theme-btn-dark,
.v-aside-nav #side-info-btn,
.v-aside-nav #side-logout-btn {
  width: 38px;
  height: 38px;
  transition: background-color 150ms ease, color 150ms ease, transform 100ms ease;
}
.v-aside-nav button:active {
  transform: scale(0.95);
}

/* P&L Bar touch & focus tooltip visibility */
.v-pnl-col[data-selected="true"] .v-pnl-tooltip,
.v-pnl-col:focus-visible .v-pnl-tooltip {
  opacity: 1 !important;
}

/* Bottom sheet handle hidden on desktop, shown on mobile */
.v-sheet-handle {
  display: none;
}

/* Responsive Wallets Grid for narrow mobile viewports */
@media (max-width: 480px) {
  .v-wallets-grid {
    grid-template-columns: repeat(1, minmax(0, 1fr)) !important;
    gap: 0.625rem !important;
  }
  .v-wallets-grid .wallet-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem !important;
    min-height: 54px;
  }
  .v-wallets-grid .wallet-card > div {
    margin: 0 !important;
  }
}

/* One-Handed Mobile Layout & Sticky Bottom Navigation (< 768px) */
@media (max-width: 767px) {
  .v-shell-container {
    padding-bottom: calc(96px + env(safe-area-inset-bottom, 0px)) !important;
  }

  .v-shell-header {
    gap: 0.5rem !important;
    margin-bottom: 1rem !important;
  }

  .v-shell-layout {
    flex-direction: column !important;
    gap: 1rem !important;
  }

  .v-aside-nav { display: none !important; }
  .v-mobile-nav {
    position: fixed;
    inset-inline: 0;
    bottom: 0;
    z-index: 45;
    display: grid;
    gap: 4px;
    padding: 6px 8px calc(6px + env(safe-area-inset-bottom, 0px));
    background: var(--bg-card);
    border-top: 1px solid var(--border-subtle);
    box-shadow: 0 -6px 24px rgba(0, 0, 0, .08);
  }
  .v-mobile-nav button {
    min-width: 0;
    min-height: 56px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    border-radius: 12px;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.3;
  }
  .v-mobile-nav button[aria-current="page"],
  .v-mobile-nav button[aria-expanded="true"] {
    background: var(--pill-active-bg);
    color: var(--pill-active-text);
  }
  .v-shell-header #top-help-btn { display: none; }
  .v-shell-header #profile-pill-btn { gap: 0; padding-inline: 6px !important; }
  .v-shell-header #profile-pill-btn > svg { display: none; }

  #v-toast {
    bottom: calc(82px + env(safe-area-inset-bottom, 0px)) !important;
    right: 16px !important;
    left: 16px !important;
    display: flex;
    justify-content: center;
  }
}

/* Mobile Bottom Sheet for Modals (< 640px) */
@media (max-width: 639px) {
  #v-modal-backdrop {
    align-items: flex-end !important;
    padding: 0 !important;
  }

  .v-modal-card {
    max-width: 100% !important;
    width: 100% !important;
    border-radius: 24px 24px 0 0 !important;
    padding: 16px 20px calc(24px + env(safe-area-inset-bottom, 10px)) !important;
  }

  .v-sheet-handle {
    display: block;
    width: 42px;
    height: 4px;
    border-radius: 999px;
    background-color: var(--toggle-off-bg);
    margin: 0 auto 14px;
  }
}

.v-mobile-nav { display: none; }
@media (max-width: 767px) { .v-mobile-nav { display: grid; } }
.v-modal-card {
  max-height: calc(100vh - 32px);
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
}
.v-modal-card input { font-size: 16px; }
.v-mobile-menu nav { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.v-mobile-menu nav button {
  display: flex; align-items: center; gap: 10px; text-align: start;
  min-height: 48px; padding: 10px 12px; border-radius: 12px;
  color: var(--text-primary); background: var(--bg-subcard); font-size: 14px;
}
.v-mobile-menu nav button svg { flex-shrink: 0; }
.v-mobile-menu nav button[aria-current="page"] { background: var(--pill-active-bg); color: var(--pill-active-text); }
.v-mobile-menu-tools { margin-top: 16px; padding-top: 8px; border-top: 1px solid var(--border-subtle); }
.v-mobile-menu-tools button {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  width: 100%; min-height: 48px; padding: 10px 4px; font-size: 14px; color: var(--text-primary);
}
.v-mobile-menu-tools button span { color: var(--text-secondary); font-size: 12px; }
@media (max-width: 639px) { .v-modal-card { max-height: calc(100dvh - 16px); } }

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
  .stagger-item {
    animation: none !important;
  }
  *, ::before, ::after {
    transition: none !important;
    animation: none !important;
  }
}
`;
