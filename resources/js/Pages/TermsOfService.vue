<script setup>
import LegalDocument from '@/Components/Marketing/LegalDocument.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'

defineProps({
  terms: { type: String, required: true },
})
</script>

<template>
  <MarketingSeo
    :title="$page.props.seo?.title || 'GexOptions Terms of Service'"
    :description="$page.props.seo?.description || 'Read the GexOptions terms of service document.'"
    :canonical="$page.props.seo?.canonical || 'https://gexoptions.com/terms-of-service'"
    :image="$page.props.seo?.image"
    :image-alt="$page.props.seo?.image_alt"
    :image-width="$page.props.seo?.image_width"
    :image-height="$page.props.seo?.image_height"
  />
  <LegalDocument title="Terms of Service" :content="terms" />
</template>
