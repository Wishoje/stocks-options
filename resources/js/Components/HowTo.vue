<template>
  <details class="mt-3 group">
    <summary class="text-[11px] text-gray-400 cursor-pointer
                    hover:text-gray-200 select-none">
      How to use this
    </summary>
    <div class="mt-2 text-xs leading-relaxed text-gray-300 space-y-1">
      <slot />
    </div>
  </details>
</template>
