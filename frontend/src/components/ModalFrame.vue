<script setup lang="ts">
defineProps<{ title: string; open: boolean }>()
defineEmits<{ close: [] }>()
</script>
<template>
  <div v-if="open" class="modal-shade" role="presentation" @click.self="$emit('close')"><section class="modal-frame" role="dialog" aria-modal="true" :aria-label="title"><header><strong>{{ title }}</strong><button aria-label="Close" @click="$emit('close')">×</button></header><div class="modal-content"><slot /></div></section></div>
</template>
