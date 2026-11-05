<script setup lang="ts">
interface Props {
  title: string
  message: string
}
defineProps<Props>()
</script>

<template>
  <div class="empty-state"><i class="icon-inbox" /><strong>{{ title }}</strong><span>{{ message }}</span></div>
</template>
