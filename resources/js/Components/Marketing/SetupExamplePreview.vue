<template>
  <figure class="mk-setup-example" aria-label="Illustrative SPY setup with entry, target and invalidation">
    <p class="mk-setup-example__eyebrow">Example setup</p>
    <div class="mk-setup-example__heading"><strong>{{ example.symbol }}</strong><span>Put wall {{ example.wall }}</span><span class="mk-setup-example__direction">Bearish</span></div>
    <div class="mk-setup-example__status"><span aria-hidden="true">✓</span><div><strong>Conditions met</strong><p>The price conditions passed in this example.</p></div></div>
    <dl class="mk-setup-example__levels">
      <div class="mk-setup-example__entry"><dt>Entry area</dt><dd>{{ example.entryLow }}–{{ example.entryHigh }}<small>Compare current price with this area.</small></dd></div>
      <div class="mk-setup-example__target"><dt>Reference target</dt><dd>{{ example.target }}</dd></div>
      <div class="mk-setup-example__invalidation"><dt>Invalid if a five-minute bar closes</dt><dd>Above {{ example.invalidation }}</dd></div>
    </dl>
    <figcaption>{{ example.caption }}</figcaption>
  </figure>
</template>

<script setup>
defineProps({ example: { type: Object, required: true } })
</script>

<style scoped>
.mk-setup-example {
  box-sizing: border-box;
  margin: 0;
  padding: clamp(1.25rem, 3vw, 2rem);
  color: #edf2fc;
  background: linear-gradient(145deg, #20253a, #151a28);
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 1.2rem;
}
.mk-setup-example__eyebrow { margin: 0; font-size: .7rem; font-weight: 650; letter-spacing: .12em; text-transform: uppercase; color: #aebcdb; }
.mk-setup-example__heading { display: flex; align-items: center; flex-wrap: wrap; gap: .65rem; color: #c4cfe4; font-size: .8rem; }
.mk-setup-example__heading > strong { font-size: 1.5rem; line-height: 1.2; color: #f3f6fd; }
.mk-setup-example__direction { margin-left: auto; border: 1px solid #865f59; border-radius: 99px; background: #38272b; padding: .3rem .65rem; color: #f3b8a9; font-size: .72rem; font-weight: 600; }
.mk-setup-example__status { display: flex; gap: .7rem; align-items: center; border-top: 1px solid #46516b; border-bottom: 1px solid #46516b; padding: 1rem 0; }
.mk-setup-example__status > span { display: grid; place-items: center; flex-shrink: 0; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: #254b40; color: #a8ecc7; font-size: .9rem; }
.mk-setup-example__status strong { font-size: .95rem; color: #b2edcf; }
.mk-setup-example__status p,
.mk-setup-example__levels small { display: block; font-size: .74rem; font-weight: 400; line-height: 1.6; color: #b8c5de; margin: .3rem 0 0; }
.mk-setup-example__levels { display: grid; gap: .8rem; margin: 0; }
.mk-setup-example__levels > div { padding: 1rem; border: 1px solid #46516b; border-radius: .65rem; background: #242b40; }
.mk-setup-example__levels dt { font-size: .76rem; line-height: 1.5; color: #c0cee5; }
.mk-setup-example__levels dd { font-size: clamp(1.05rem, 1.6vw, 1.25rem); font-weight: 650; line-height: 1.5; margin: .35rem 0 0; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.mk-setup-example__levels .mk-setup-example__entry { border-color: #8075ad; background: #2c2b48; }
.mk-setup-example__target dd { color: #a8e5c1; }
.mk-setup-example__invalidation dd { color: #efb2a2; }
.mk-setup-example figcaption { font-size: .72rem; line-height: 1.65; color: #b1bfd6; margin: 0; }
@media (max-width: 24rem) {
  .mk-setup-example { gap: 1rem; }
  .mk-setup-example__levels > div { padding: .8rem; }
}
</style>
