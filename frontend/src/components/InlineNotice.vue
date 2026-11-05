<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger'; title?: string }>(), { tone: 'info' })
</script>
<template><div class="alert" :class="`alert-${tone}`" role="status"><strong v-if="title">{{ title }} </strong><slot /></div></template>
