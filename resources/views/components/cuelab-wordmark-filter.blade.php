<svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute">
    <filter id="cue-wordmark-dark" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
        {{-- Lighten the dark lettering and preserve the source red. White pixels contribute to neither layer. --}}
        <feColorMatrix in="SourceGraphic" result="lettering" values="
             0    0    0 0 1
             0    0    0 0 .953
             0    0    0 0 .933
            -1.1  0    0 0 1.04"/>
        <feColorMatrix in="SourceGraphic" result="accent" values="
             1    0    0 0 0
             0    1    0 0 0
             0    0    1 0 0
             1.4 -1.4  0 0 -.06"/>
        <feMerge>
            <feMergeNode in="lettering"/>
            <feMergeNode in="accent"/>
        </feMerge>
    </filter>
</svg>
