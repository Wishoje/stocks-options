<script setup>
import LegalDocument from '@/Components/Marketing/LegalDocument.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'

defineProps({
  policy: { type: String, required: true },
})
</script>

<template>
  <MarketingSeo
    :title="$page.props.seo?.title || 'GexOptions Privacy Policy'"
    :description="$page.props.seo?.description || 'Read the GexOptions privacy policy document.'"
    :canonical="$page.props.seo?.canonical || 'https://gexoptions.com/privacy-policy'"
    :image="$page.props.seo?.image"
    :image-alt="$page.props.seo?.image_alt"
    :image-width="$page.props.seo?.image_width"
    :image-height="$page.props.seo?.image_height"
  />
  <LegalDocument title="Privacy Policy" :content="policy" />
</template>
