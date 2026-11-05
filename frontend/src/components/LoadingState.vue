<script setup lang="ts">
defineProps<{ label?: string }>()
</script>
<template><div class="loading-state" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true" /><span>{{ label || 'Loading…' }}</span></div></template>
