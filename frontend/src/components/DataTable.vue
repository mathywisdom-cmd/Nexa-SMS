<script setup lang="ts">
defineProps<{ columns: string[] }>()
</script>
<template>
  <div class="table-responsive"><table class="table"><thead><tr><th v-for="column in columns" :key="column">{{ column }}</th></tr></thead><tbody><slot /></tbody></table></div>
</template>
