<script setup lang="ts">
withDefaults(defineProps<{ kind?: 'primary' | 'light' | 'danger'; disabled?: boolean }>(), { kind: 'primary', disabled: false })
</script>
<template><button class="btn" :class="`btn-${kind}`" :disabled="disabled"><slot /></button></template>
