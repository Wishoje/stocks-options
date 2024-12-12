<template>
    <div class="p-4 bg-gray-100 min-h-screen">
      <div class="max-w-md mx-auto bg-white shadow-md rounded p-4">
        <h1 class="text-xl font-bold mb-4">GEX Levels for {{ symbol.toUpperCase() }}</h1>
        <div class="flex gap-2 mb-4">
          <button 
            v-for="sym in symbols"
            :key="sym"
            @click="fetchData(sym)"
            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
          >
            {{ sym.toUpperCase() }}
          </button>
        </div>
  
        <div v-if="loading" class="text-gray-700">Loading...</div>
  
        <div v-if="error" class="text-red-500 font-bold">{{ error }}</div>
  
        <div v-if="levels && !loading && !error">
          <p><strong>Expiration Date:</strong> {{ levels.expiration_date }}</p>
          <p><strong>HVL:</strong> {{ levels.HVL }}</p>
  
          <h2 class="font-semibold mt-4">Call Resistance & Walls:</h2>
          <ul class="list-disc ml-5">
            <li>Call Resistance: {{ levels.call_resistance }}</li>
            <li>2nd Call Wall: {{ levels.call_wall_2 }}</li>
            <li>3rd Call Wall: {{ levels.call_wall_3 }}</li>
          </ul>
  
          <h2 class="font-semibold mt-4">Put Support & Walls:</h2>
          <ul class="list-disc ml-5">
            <li>Put Support: {{ levels.put_support }}</li>
            <li>2nd Put Wall: {{ levels.put_wall_2 }}</li>
            <li>3rd Put Wall: {{ levels.put_wall_3 }}</li>
          </ul>
        </div>
      </div>
    </div>
  </template>
  
  <script setup>
  import { ref } from 'vue'
  
  const symbols = ['spy', 'iwm', 'qqq']
  const symbol = ref('spy')
  const levels = ref(null)
  const loading = ref(false)
  const error = ref(null)
  
  async function fetchData(sym) {
    symbol.value = sym
    loading.value = true
    error.value = null
    levels.value = null
    try {
      const response = await fetch(`/api/gex-levels?symbol=${sym.toUpperCase()}`)
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      const data = await response.json()
      levels.value = data
    } catch (err) {
      error.value = "Failed to load GEX levels."
    } finally {
      loading.value = false
    }
  }
  
  // Fetch initial data
  fetchData(symbol.value)
  </script>
  
  <style scoped>
  </style>
  