<template>
  <figure class="mk-swing-preview" aria-label="How Swing ideas works">
    <div class="mk-swing-preview__heading"><span>Swing ideas</span><span class="mk-swing-preview__tag">Daily-close plans</span></div>
    <div class="mk-swing-preview__horizons">
      <div><strong>2–5 days</strong><span>Plan a shorter hold</span></div>
      <div><strong>1–3 weeks</strong><span>Give the idea more time</span></div>
    </div>
    <ol class="mk-swing-preview__steps">
      <li><span aria-hidden="true">01</span><strong>A price condition</strong></li>
      <li><span aria-hidden="true">02</span><strong>An entry area</strong></li>
      <li><span aria-hidden="true">03</span><strong>Target + invalidation</strong></li>
    </ol>
    <figcaption>Follow the plan as new daily closes arrive.</figcaption>
  </figure>
</template>

<style scoped>
.mk-swing-preview{margin:0;padding:clamp(1.2rem,3vw,2rem);background:linear-gradient(145deg,#172337,#101723);color:#e8f0fc;border-top:1px solid var(--mk-line);box-sizing:border-box}.mk-swing-preview__heading{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.7rem;font-size:1rem;font-weight:650}.mk-swing-preview__tag{font-size:.7rem;font-weight:500;color:#a8dce7;border:1px solid #3f626e;border-radius:999px;padding:.35rem .65rem}.mk-swing-preview__horizons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem;margin:1.3rem 0}.mk-swing-preview__horizons>div{display:grid;gap:.4rem;padding:1rem;border:1px solid #405b77;border-radius:.7rem;background:#1c2e43}.mk-swing-preview__horizons>div+div{background:#252b47;border-color:#565579}.mk-swing-preview__horizons strong{font-size:1.25rem;color:#dcecfb}.mk-swing-preview__horizons span{font-size:.75rem;color:#b7c9de}.mk-swing-preview__steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.8rem;list-style:none;margin:0;padding:0}.mk-swing-preview__steps li{display:grid;align-content:start;gap:.5rem}.mk-swing-preview__steps li>span{display:grid;place-items:center;width:1.7rem;height:1.7rem;border:1px solid #4c647a;border-radius:50%;color:#9cdbe4;font-size:.7rem}.mk-swing-preview__steps strong{display:block;font-size:.78rem;line-height:1.5;font-weight:600}.mk-swing-preview figcaption{font-size:.73rem;line-height:1.6;color:#a9bed7;border-top:1px solid #35495e;padding-top:.9rem;margin-top:1.2rem}@media(min-width:72rem){.mk-feature-row__media .mk-swing-preview{border-top:0}}
</style>
